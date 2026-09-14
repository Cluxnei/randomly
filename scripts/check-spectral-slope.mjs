/**
 * Measure the power-law slope of a spectral noise field and report it.
 *
 * `patterns.spectral` makes one falsifiable claim: the field it draws has a power
 * spectrum proportional to f^(−β), for the β on the slider. That claim lives in
 * the browser, so the only honest way to check it from PHP is to build the real
 * field and measure it — which is what this does, and what
 * tests/Unit/StructuredPatternsTest.php asserts against.
 *
 * The forward transform below is written out here rather than imported from
 * spectral.js *on purpose*. Synthesis uses the inverse transform and this uses
 * the forward one, and sharing a butterfly between them would let an error in it
 * cancel itself: a field built wrongly and measured with the same wrongness can
 * still read as a perfect power law. Two implementations that agree are evidence;
 * one implementation agreeing with itself is not.
 *
 *   node scripts/check-spectral-slope.mjs <beta> <size> <seed>
 */
import { field } from '../resources/js/patterns/spectral.js'

const beta = Number(process.argv[2])
const n = Number(process.argv[3] || 256)
const seed = Number(process.argv[4] || 1234567)

/** Iterative radix-2 forward DFT over one strided line. Deliberately its own. */
function fft (re, im, offset, stride, size) {
  for (let i = 1, j = 0; i < size; i++) {
    let bit = size >> 1
    for (; j & bit; bit >>= 1) j ^= bit
    j ^= bit

    if (i < j) {
      const a = offset + i * stride
      const b = offset + j * stride
      let t = re[a]; re[a] = re[b]; re[b] = t
      t = im[a]; im[a] = im[b]; im[b] = t
    }
  }

  for (let len = 2; len <= size; len <<= 1) {
    const half = len >> 1

    for (let i = 0; i < size; i += len) {
      for (let k = 0; k < half; k++) {
        // Trig per butterfly rather than by recurrence: slower, and immune to
        // the accumulated phase drift a recurrence would share with the
        // implementation under test.
        const angle = -2 * Math.PI * k / len
        const wr = Math.cos(angle)
        const wi = Math.sin(angle)

        const a = offset + (i + k) * stride
        const b = offset + (i + k + half) * stride

        const xr = re[b] * wr - im[b] * wi
        const xi = re[b] * wi + im[b] * wr

        re[b] = re[a] - xr
        im[b] = im[a] - xi
        re[a] += xr
        im[a] += xi
      }
    }
  }
}

const source = field(seed, n, beta)

const re = new Float64Array(n * n)
const im = new Float64Array(n * n)
for (let i = 0; i < n * n; i++) re[i] = source[i]

for (let y = 0; y < n; y++) fft(re, im, y * n, 1, n)
for (let x = 0; x < n; x++) fft(re, im, x, n, n)

/*
 * Radially average the power, in logarithmic frequency bins.
 *
 * Linear bins would weight the measurement overwhelmingly towards the highest
 * frequencies — a plane holds about f modes at radius f, so the top octave alone
 * is half of them — and the fit would then say almost nothing about the shape of
 * the rest of the spectrum. Log bins give every octave equal say, which is the
 * thing a power law is a statement about.
 */
const half = n / 2
const bins = 24
const power = new Float64Array(bins)
const counts = new Int32Array(bins)

const minF = 2
const maxF = half - 1
const logMin = Math.log(minF)
const scale = (bins - 1) / (Math.log(maxF) - logMin)

for (let y = 0; y < n; y++) {
  const fy = y <= half ? y : y - n

  for (let x = 0; x < n; x++) {
    const fx = x <= half ? x : x - n
    const f = Math.sqrt(fx * fx + fy * fy)

    if (f < minF || f > maxF) continue

    const b = Math.round((Math.log(f) - logMin) * scale)
    const i = y * n + x

    power[b] += re[i] * re[i] + im[i] * im[i]
    counts[b]++
  }
}

// Least squares of log(mean power) against log(frequency).
let sx = 0
let sy = 0
let sxx = 0
let sxy = 0
let used = 0

for (let b = 0; b < bins; b++) {
  if (counts[b] === 0) continue

  const f = Math.exp(logMin + b / scale)
  const lx = Math.log(f)
  const ly = Math.log(power[b] / counts[b])

  sx += lx
  sy += ly
  sxx += lx * lx
  sxy += lx * ly
  used++
}

const slope = (used * sxy - sx * sy) / (used * sxx - sx * sx)

console.log(JSON.stringify({ beta, size: n, bins: used, slope, expected: -beta }))
