/**
 * A mesh gradient: a handful of coloured control points, blended over the plane.
 *
 *   Gaussian   w(p, cᵢ) = exp(−|p − cᵢ|² / 2σ²)
 *   Shepard    w(p, cᵢ) = |p − cᵢ|^(−k)
 *
 *   colour(p) = Σ wᵢ · colourᵢ  /  Σ wᵢ
 *
 * Both are inverse-distance blends and they look nothing like each other.
 * Gaussian weights fall off smoothly and give the soft, liquid wash the phrase
 * "mesh gradient" usually means; Shepard's weights are infinite at the control
 * points, so each one holds its own colour exactly and the field between them
 * develops the flat cell interiors and taut boundaries of a stained-glass window.
 *
 * **The dither is not decoration.** A gradient is the one thing eight bits per
 * channel genuinely cannot represent: across a 900px canvas a channel might move
 * through forty levels, so each level is a twenty-pixel band with a hard edge,
 * and the eye's lateral inhibition makes those edges far more visible than the
 * 1/255 step that causes them. Adding a sub-LSB ordered offset before the round
 * moves the decision point from pixel to pixel, and the bands dissolve into a
 * texture below the resolution of the display. One line, and it is the whole
 * difference between output that looks cheap and output that looks designed.
 */
import { fractal } from '../patterns/perlin.js'
import { Surface, hexToRgb } from './raster.js'

/** A permutation table plus slack, for the domain warp. */
export function bytesNeeded () {
  return 4096
}

/**
 * The 8×8 Bayer matrix, as thresholds in [0, 1).
 *
 * Built rather than typed out: the recursive definition is four lines and the
 * literal is sixty-four numbers nobody can check by eye.
 *
 *   M₂ₙ = [[4Mₙ, 4Mₙ+2], [4Mₙ+3, 4Mₙ+1]]
 *
 * Ordered dither rather than a random offset: blue-noise error would be quieter
 * still, but ordered dither is deterministic, costs one array lookup, and its
 * texture at 8×8 sits above the frequency where a gradient bands and below the
 * one where the eye reads structure.
 */
const BAYER = (() => {
  let m = [[0]]

  while (m.length < 8) {
    const n = m.length
    const next = Array.from({ length: n * 2 }, () => new Array(n * 2))

    for (let y = 0; y < n; y++) {
      for (let x = 0; x < n; x++) {
        next[y][x] = 4 * m[y][x]
        next[y][x + n] = 4 * m[y][x] + 2
        next[y + n][x] = 4 * m[y][x] + 3
        next[y + n][x + n] = 4 * m[y][x] + 1
      }
    }

    m = next
  }

  const flat = new Float32Array(64)
  for (let y = 0; y < 8; y++) {
    for (let x = 0; x < 8; x++) flat[y * 8 + x] = (m[y][x] + 0.5) / 64 - 0.5
  }

  return flat
})()

export function render (ctx, spec, rng) {
  const { width, height, stops } = spec
  const surface = new Surface(width, height)

  const colours = spec.palette.map(hexToRgb)
  const count = stops.length

  // Positions arrive as fractions of the canvas so the same spec renders at any
  // size; the composition is a property of the seed, not of the pixel count.
  const px = new Float64Array(count)
  const py = new Float64Array(count)
  const cr = new Float64Array(count)
  const cg = new Float64Array(count)
  const cb = new Float64Array(count)

  for (let i = 0; i < count; i++) {
    px[i] = stops[i][0] * width
    py[i] = stops[i][1] * height
    const c = colours[Math.min(colours.length - 1, stops[i][2])]
    cr[i] = c[0]
    cg[i] = c[1]
    cb[i] = c[2]
  }

  /*
   * Scale σ with the spacing between control points, not with the canvas.
   *
   * A fixed σ makes four stops a fog and sixteen stops a set of hard blobs, so
   * the number of stops would double as a sharpness control and neither end of
   * it would be usable. Dividing the canvas area between the points and taking
   * the square root gives the typical distance from a point to its neighbours,
   * which is the length the falloff should be measured in.
   */
  const spacing = Math.sqrt((width * height) / Math.max(1, count))
  const sigma = spacing * spec.falloff
  const twoSigmaSquared = 2 * sigma * sigma

  const gaussian = spec.blend !== 'sharp'
  const power = spec.power

  // Drawn unconditionally even when the warp is off: the stream is read in
  // order, and a table drawn only sometimes would make one seed produce two
  // different pictures depending on where a slider sat.
  const perm = rng.permutation()
  const warp = spec.warp * Math.min(width, height)
  const warpStep = spec.warp_scale / Math.max(width, height)
  const noise = { octaves: 3, persistence: 0.5, lacunarity: 2.0, variant: 'fbm' }

  const data = surface.data
  const dither = spec.dither

  for (let y = 0, p = 0; y < height; y++) {
    const bayerRow = (y & 7) * 8

    for (let x = 0; x < width; x++, p += 3) {
      let sx = x
      let sy = y

      if (warp > 0) {
        /*
         * Domain warp, exactly as patterns.perlin does it: displace the point
         * before evaluating the field. On a gradient it is the difference
         * between a blend and something that looks poured — the isolines stop
         * being circles and start folding around each other.
         */
        sx += fractal(perm, x * warpStep, y * warpStep, noise) * warp
        sy += fractal(perm, x * warpStep + 5.2, y * warpStep + 1.3, noise) * warp
      }

      let r = 0
      let g = 0
      let b = 0
      let total = 0

      for (let i = 0; i < count; i++) {
        const dx = sx - px[i]
        const dy = sy - py[i]
        const d2 = dx * dx + dy * dy

        // The epsilon keeps Shepard's weights finite at the control point
        // itself, where the true weight is infinite and the true colour is
        // simply that point's own.
        const w = gaussian
          ? Math.exp(-d2 / twoSigmaSquared)
          : Math.pow(d2 + 1e-6, -power / 2)

        r += cr[i] * w
        g += cg[i] * w
        b += cb[i] * w
        total += w
      }

      // Every Gaussian weight underflows to zero far enough from every stop;
      // painting that black would put a hole in the middle of a gradient.
      if (total <= 0) total = 1

      /*
       * The dither offset goes in here, at full float precision, and is rounded
       * by the Uint8ClampedArray in commit(). Adding it after the round would
       * do nothing at all — the banding has already happened by then.
       */
      const offset = BAYER[bayerRow + (x & 7)] * dither

      data[p] = r / total + offset
      data[p + 1] = g / total + offset
      data[p + 2] = b / total + offset
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
