/**
 * Perlin noise, fBm and domain warping — drawn from the same stream the server
 * would have used.
 *
 * The permutation table is the only thing derived from the Rng: everything after
 * it is a pure function of the lattice, which is what makes a 540k-pixel field
 * affordable. Drawing a random number per pixel would be both slower and wrong,
 * since the field has to be continuous.
 */

const GRADIENTS = [
  [1, 1], [-1, 1], [1, -1], [-1, -1],
  [1, 0], [-1, 0], [0, 1], [0, -1],
]

/** Quintic fade: 6t⁵ − 15t⁴ + 10t³. C² continuous, so no creases at cell edges. */
function fade (t) {
  return t * t * t * (t * (t * 6 - 15) + 10)
}

function lerp (a, b, t) {
  return a + t * (b - a)
}

/** Classic 2D Perlin. Returns roughly [-1, 1]; exactly 0 at every lattice point. */
export function perlin2 (perm, x, y) {
  const xi = Math.floor(x) & 255
  const yi = Math.floor(y) & 255
  const xf = x - Math.floor(x)
  const yf = y - Math.floor(y)

  const u = fade(xf)
  const v = fade(yf)

  const aa = perm[perm[xi] + yi]
  const ab = perm[perm[xi] + yi + 1]
  const ba = perm[perm[xi + 1] + yi]
  const bb = perm[perm[xi + 1] + yi + 1]

  const g = (hash, dx, dy) => {
    const grad = GRADIENTS[hash & 7]
    return grad[0] * dx + grad[1] * dy
  }

  return lerp(
    lerp(g(aa, xf, yf), g(ba, xf - 1, yf), u),
    lerp(g(ab, xf, yf - 1), g(bb, xf - 1, yf - 1), u),
    v,
  )
}

/**
 * Stack octaves.
 *
 *   fBm(p)        = Σ Aᵢ · n(fᵢ p)
 *   turbulence(p) = Σ Aᵢ · |n(fᵢ p)|
 *   ridged(p)     = Σ Aᵢ · (1 − |n(fᵢ p)|)²
 *   billow(p)     = Σ Aᵢ · (2|n(fᵢ p)| − 1)
 *
 * Four visually unrelated textures from one sign change apiece.
 */
export function fractal (perm, x, y, { octaves, persistence, lacunarity, variant }) {
  let amplitude = 1
  let frequency = 1
  let sum = 0
  let total = 0

  for (let o = 0; o < octaves; o++) {
    const n = perlin2(perm, x * frequency, y * frequency)

    let shaped
    switch (variant) {
      case 'turbulence': shaped = Math.abs(n); break
      case 'ridged': { const r = 1 - Math.abs(n); shaped = r * r; break }
      case 'billow': shaped = 2 * Math.abs(n) - 1; break
      default: shaped = n
    }

    sum += shaped * amplitude
    total += amplitude
    amplitude *= persistence
    frequency *= lacunarity
  }

  return sum / total
}

/**
 * Fold the field through a copy of itself.
 *
 *   q    = (fBm(p + o₁), fBm(p + o₂))
 *   out  = fBm(p + strength · q)
 *
 * Costs three times the noise evaluations and is worth every one of them: it is
 * the difference between output that reads as "computer texture" and output that
 * reads as something grown.
 */
function warped (perm, x, y, spec) {
  if (spec.warp <= 0) return fractal(perm, x, y, spec)

  const qx = fractal(perm, x, y, spec)
  const qy = fractal(perm, x + 5.2, y + 1.3, spec)

  return fractal(perm, x + spec.warp * qx * 4, y + spec.warp * qy * 4, spec)
}

function hexToRgb (hex) {
  return [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
  ]
}

export function render (ctx, spec, rng) {
  const { width, height, palette, contours } = spec
  const perm = rng.permutation()
  const colours = palette.map(hexToRgb)
  const last = colours.length - 1

  const step = spec.scale / Math.max(width, height)
  const [ox, oy] = spec.offset

  /*
   * Two passes, because no fixed remap can serve every variant. Summed octaves
   * pull towards the middle (more octaves, tighter the clustering), and the
   * variants do not even share a range — ridged lands in [0,1] while fbm spans
   * [-1,1]. Measuring the field and then stretching it means the palette is
   * always used end to end, whatever the sliders say.
   *
   * The cost is one extra traversal of an array already in cache, which is far
   * cheaper than the noise evaluation it saves us from repeating.
   */
  const field = new Float32Array(width * height)
  let min = Infinity
  let max = -Infinity

  let f = 0
  for (let y = 0; y < height; y++) {
    const ny = y * step + oy
    for (let x = 0; x < width; x++) {
      field[f] = warped(perm, x * step + ox, ny, spec)

      // Read the value back rather than tracking the double we just computed.
      // Float32Array rounds on store, so a double compared here can sit a hair
      // outside the range the stored floats actually occupy — and a t of -1e-7
      // floors to index -1, which is undefined rather than the first colour.
      const n = field[f++]
      if (n < min) min = n
      if (n > max) max = n
    }
  }

  // A perfectly flat field (scale so low the canvas sits inside one cell) would
  // divide by zero; paint it the middle of the ramp rather than NaN.
  const span = max - min || 1

  const image = ctx.createImageData(width, height)
  const data = image.data

  for (let i = 0, p = 0; i < field.length; i++) {
    const t = (field[i] - min) / span

    let r, g, b
    if (contours) {
      const c = colours[Math.min(last, Math.floor(t * (last + 1)))]
      r = c[0]; g = c[1]; b = c[2]
    } else {
      const position = t * last
      const lower = Math.floor(position)
      const upper = Math.min(last, lower + 1)
      const k = position - lower
      const a = colours[lower]
      const c = colours[upper]
      r = a[0] + (c[0] - a[0]) * k
      g = a[1] + (c[1] - a[1]) * k
      b = a[2] + (c[2] - a[2]) * k
    }

    data[p++] = r
    data[p++] = g
    data[p++] = b
    data[p++] = 255
  }

  ctx.putImageData(image, 0, 0)
}
