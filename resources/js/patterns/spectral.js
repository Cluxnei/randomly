/**
 * 1/f^β noise, built in the frequency domain and transformed back.
 *
 *     |F(f)| ∝ f^(−β/2),   arg F(f) ~ U(0, 2π)
 *
 * Every other noise generator here builds a field in space and lets its spectrum
 * fall out. This one does the opposite: it *states* the spectrum, fills it with
 * random phase, and inverse-transforms. That is the only way to get an exact
 * power law, and it is why β is a continuous slider — β = 0 is white, 1 is pink,
 * 2 is brown, −1 is blue, and everything between is reachable.
 *
 * The same construction as the audio module's noise colours, one dimension apart
 * (docs/07 §4). There a spectrum is shaped across frequency; here across spatial
 * frequency, and the ear's "pink" and the eye's "cloud" are the same statement.
 *
 * Two implementation notes worth having up front.
 *
 * **Gaussian real and imaginary parts, not unit magnitude with random phase.**
 * Both give the right expected spectrum, but only the Gaussian version gives the
 * right *distribution* around it — the χ²₂ scatter of a real random field. A
 * fixed-magnitude spectrum produces a field that is subtly too even, and at β = 0
 * it is visibly not white noise.
 *
 * **The real part is taken rather than enforcing Hermitian symmetry.** Re(x) of
 * the inverse transform of a non-Hermitian spectrum is itself a sum of
 * random-phase cosines with exactly the specified amplitudes, so its power
 * spectrum is the one asked for. Enforcing conjugate symmetry across a 1024²
 * plane is a page of index arithmetic to arrive at the same statistics.
 */
import { splitmix32 } from './prng.js'
import { hexToRgb, paintField } from './shared.js'

/**
 * Largest transform side.
 *
 * A 2D FFT is 2N transforms of length N, so the cost doubles and then some with
 * every step up. 1024 is about 250ms, which a slider drag survives; 2048 is over
 * a second and 64MB of Float64Array, which it does not. Canvases larger than this
 * sample the field with wraparound — the field is genuinely periodic, being a
 * finite Fourier sum, so that is a tiling rather than a stretch.
 */
const MAX_SIDE = 1024

/** The smallest power of two at least `n`, clamped to what the budget allows. */
function side (n) {
  let s = 16
  while (s < n && s < MAX_SIDE) s *= 2

  return s
}

/**
 * In-place complex FFT over one strided line of a 2D array.
 *
 * Iterative radix-2 Cooley–Tukey with the bit reversal done first. Strided rather
 * than copying each row and column out to a scratch buffer: the copy would be
 * four extra traversals of a megabyte-scale array per pass, and the stride costs
 * nothing but an index multiply the engine hoists anyway.
 *
 * `sign` is +1 for the forward transform and −1 for the inverse. Only the inverse
 * is used here, and unscaled: the 1/N² an inverse DFT would normally carry is a
 * constant over the whole field, and the two-pass normalise at the end removes
 * any constant regardless.
 */
function fftLine (re, im, offset, stride, n, sign) {
  // Bit-reversal permutation.
  for (let i = 1, j = 0; i < n; i++) {
    let bit = n >> 1
    for (; j & bit; bit >>= 1) j ^= bit
    j ^= bit

    if (i < j) {
      const a = offset + i * stride
      const b = offset + j * stride
      let t = re[a]; re[a] = re[b]; re[b] = t
      t = im[a]; im[a] = im[b]; im[b] = t
    }
  }

  for (let len = 2; len <= n; len <<= 1) {
    const angle = sign * 2 * Math.PI / len
    const wr = Math.cos(angle)
    const wi = Math.sin(angle)

    for (let i = 0; i < n; i += len) {
      let cr = 1
      let ci = 0

      for (let k = 0; k < len / 2; k++) {
        const a = offset + (i + k) * stride
        const b = offset + (i + k + len / 2) * stride

        const xr = re[b] * cr - im[b] * ci
        const xi = re[b] * ci + im[b] * cr

        re[b] = re[a] - xr
        im[b] = im[a] - xi
        re[a] += xr
        im[a] += xi

        // Recurrence rather than a cos/sin per butterfly. The drift over a
        // length-1024 pass is around 1e-13, far below anything an 8-bit
        // picture could show, and it removes twenty million trig calls.
        const nr = cr * wr - ci * wi
        ci = cr * wi + ci * wr
        cr = nr
      }
    }
  }
}

/**
 * Build the field itself: an n×n Float32Array of 1/f^β noise.
 *
 * Exported so scripts/check-spectral-slope.mjs can measure the spectrum of the
 * real output rather than of a second implementation written for the test.
 */
export function field (seed, n, beta) {
  const random = splitmix32(seed >>> 0)
  const re = new Float64Array(n * n)
  const im = new Float64Array(n * n)

  // Box–Muller, inline: this runs n² times and the pair it produces is used in
  // full, one draw for each of the two components of the complex coefficient.
  for (let i = 0; i < n * n; i++) {
    const u1 = 1 - random()
    const u2 = random()
    const r = Math.sqrt(-2 * Math.log(u1))

    re[i] = r * Math.cos(2 * Math.PI * u2)
    im[i] = r * Math.sin(2 * Math.PI * u2)
  }

  const half = n / 2
  const exponent = -beta / 2

  for (let y = 0; y < n; y++) {
    // Frequencies above the Nyquist index are the negative ones; an FFT stores
    // them wrapped, and treating them as high frequencies would produce a field
    // with a bright cross through the middle of its spectrum.
    const fy = y <= half ? y : y - n

    for (let x = 0; x < n; x++) {
      const fx = x <= half ? x : x - n
      const i = y * n + x

      if (fx === 0 && fy === 0) {
        // The DC term is the field's mean. f^(−β/2) is infinite there for any
        // positive β, and the mean is exactly the thing the normalise pass
        // throws away, so it is set to zero rather than to something arbitrary.
        re[i] = 0
        im[i] = 0
        continue
      }

      const amplitude = Math.pow(Math.sqrt(fx * fx + fy * fy), exponent)
      re[i] *= amplitude
      im[i] *= amplitude
    }
  }

  for (let y = 0; y < n; y++) fftLine(re, im, y * n, 1, n, -1)
  for (let x = 0; x < n; x++) fftLine(re, im, x, n, n, -1)

  const out = new Float32Array(n * n)
  for (let i = 0; i < n * n; i++) out[i] = re[i]

  return out
}

export function render (ctx, spec) {
  const { width, height, beta, palette } = spec
  const n = side(Math.max(width, height))
  const source = field(spec.seed, n, beta)

  const colours = palette.map(hexToRgb)
  const out = new Float32Array(width * height)

  /*
   * Zoom magnifies the same field rather than regenerating a smaller one.
   *
   * At 1 the field is drawn a sample to a pixel, which is the honest picture of
   * the spectrum: pink and white noise really are fine-grained static, and
   * quietly smoothing them would be a lie about what β = 0 means. Above 1 the
   * high end walks off the edge of the raster and the low frequencies take over,
   * which is how a β = 1 field turns from grain into weather — and it is a true
   * zoom, because a 1/f^β field is scale-free and has no intrinsic size.
   *
   * Bilinear, and wrapped at the transform's edge in both directions: a finite
   * Fourier sum is exactly periodic, so the wrap is seamless rather than a tiling
   * seam. That is also what lets a canvas larger than the transform work at all.
   */
  const zoom = Math.max(1, spec.zoom || 1)

  for (let y = 0, i = 0; y < height; y++) {
    const fy = y / zoom
    const y0 = Math.floor(fy)
    const ty = fy - y0
    const r0 = (((y0 % n) + n) % n) * n
    const r1 = ((((y0 + 1) % n) + n) % n) * n

    for (let x = 0; x < width; x++, i++) {
      const fx = x / zoom
      const x0 = Math.floor(fx)
      const tx = fx - x0
      const c0 = ((x0 % n) + n) % n
      const c1 = (((x0 + 1) % n) + n) % n

      const top = source[r0 + c0] + (source[r0 + c1] - source[r0 + c0]) * tx
      const bottom = source[r1 + c0] + (source[r1 + c1] - source[r1 + c0]) * tx

      out[i] = top + (bottom - top) * ty
    }
  }

  clip(out, spec.contrast || 2.5)

  paintField(ctx, out, width, height, colours, { contours: spec.contours })
}

/**
 * Rescale into [0, 1] about the mean, clipped at ±k standard deviations.
 *
 * The straight min/max stretch every other field renderer uses is wrong for this
 * one, and the failure is total rather than subtle: a spectral field is very
 * nearly Gaussian, so half a million samples reach ±4.7σ while 99% of them sit
 * inside ±2.6σ. Stretching to the extremes leaves the whole picture in the
 * middle third of the ramp — the first blue-noise render came out as a single
 * flat teal rectangle with a hint of grain.
 *
 * A Worley or reaction field has real bounds and min/max is the right answer
 * there; a Gaussian one has tails instead of bounds, and tails have to be clipped
 * rather than accommodated. k is the `contrast` control, so the trade between
 * "everything visible" and "the extremes are blown" is the user's.
 *
 * Values are read back out of the Float32Array before being compared, for the
 * reason docs/07 §9 gives: storing rounds to float32.
 */
function clip (field, k) {
  let sum = 0
  for (let i = 0; i < field.length; i++) sum += field[i]

  const mean = sum / field.length

  let variance = 0
  for (let i = 0; i < field.length; i++) {
    const d = field[i] - mean
    variance += d * d
  }

  // A field of one repeated value — reachable only by asking for a canvas
  // smaller than one sample — would divide by zero.
  const spread = Math.sqrt(variance / field.length) * k || 1

  for (let i = 0; i < field.length; i++) {
    const t = (field[i] - mean) / (2 * spread) + 0.5
    field[i] = t < 0 ? 0 : t > 1 ? 1 : t
  }
}
