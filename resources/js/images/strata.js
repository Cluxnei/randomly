/**
 * Horizontal band layers, each with a wandering boundary.
 *
 * The band heights come from a Dirichlet draw on the server — see
 * StrataGenerator, which explains why that particular distribution is the whole
 * design. What happens here is the part that makes it look like rock rather than
 * like a bar chart: every boundary is displaced by its own one-dimensional
 * fractal noise, and every band is filled with a slight vertical gradient.
 *
 * Both matter more than they sound.
 *
 * A perfectly straight boundary reads as a chart, because straight lines in
 * nature are rare enough that the eye treats them as a claim about data. Give
 * each boundary its own amplitude and frequency and the same heights read as
 * deposition — and because each boundary wanders independently, bands pinch out
 * and reappear the way real strata do.
 *
 * The gradient inside a band is what gives it a top and a bottom. Flat fills make
 * the picture a stack of coloured strips with nothing behind them; a band that is
 * a shade darker at its base reads as a surface with light falling on it, for one
 * extra interpolation per pixel.
 */
import { perlin2 } from '../patterns/perlin.js'
import { Surface, hexToRgb, sampleRamp } from './raster.js'

/** A permutation table, and nothing else. */
export function bytesNeeded () {
  return 2048
}

/** Entries in the colour lookup table. Past what 8-bit output can resolve. */
const RAMP_STEPS = 512

/**
 * One-dimensional fractal noise along x.
 *
 * Built from the same 2D Perlin the rest of the project uses, sampled along a
 * line at a fixed y. Writing a separate 1D noise would be a second noise
 * implementation to keep honest, and the line through a 2D field has exactly the
 * statistics wanted here.
 */
function ridge (perm, x, phase, octaves) {
  let amplitude = 1
  let frequency = 1
  let sum = 0
  let total = 0

  for (let o = 0; o < octaves; o++) {
    sum += perlin2(perm, x * frequency, phase * frequency) * amplitude
    total += amplitude
    amplitude *= 0.5
    frequency *= 2.07
  }

  return sum / total
}

export function render (ctx, spec, rng) {
  const { width, height, bands, palette } = spec
  const perm = rng.permutation()

  const surface = new Surface(width, height)
  const [br, bg, bb] = hexToRgb(spec.background)
  surface.fill(br, bg, bb)

  const ramp = new Float32Array(RAMP_STEPS * 3)
  const stops = palette.map(hexToRgb)

  for (let i = 0; i < RAMP_STEPS; i++) {
    const [r, g, b] = sampleRamp(stops, i / (RAMP_STEPS - 1))
    ramp[i * 3] = r
    ramp[i * 3 + 1] = g
    ramp[i * 3 + 2] = b
  }

  const n = bands.length
  const edges = new Float64Array(n + 1)

  // Cumulative heights, in pixels, before any displacement. The Dirichlet draw
  // guarantees these sum to one, so the last edge lands exactly on the bottom.
  const base = new Float64Array(n + 1)
  let running = 0

  for (let i = 0; i < n; i++) {
    running += bands[i][0]
    base[i + 1] = running * height
  }

  const step = spec.frequency / width
  const octaves = spec.octaves

  for (let x = 0; x < width; x++) {
    edges[0] = 0
    edges[n] = height

    for (let i = 1; i < n; i++) {
      const [, , amplitude, phase, tilt] = bands[i]

      const displaced = base[i]
        + ridge(perm, x * step, phase, octaves) * amplitude * height
        + tilt * (x / width - 0.5) * height

      /*
       * Boundaries must stay in order.
       *
       * Two neighbouring boundaries displaced in opposite directions can cross,
       * and a band of negative height would otherwise be drawn upside down over
       * the one above it — a bright horizontal scar across the picture. Clamping
       * to the boundary above instead pinches the band out to nothing, which is
       * exactly what a real stratum does where it runs out of sediment.
       */
      edges[i] = Math.min(height, Math.max(edges[i - 1], displaced))
    }

    for (let i = 0; i < n; i++) {
      const top = edges[i]
      const bottom = edges[i + 1]
      if (bottom <= top) continue

      const shadeTop = bands[i][1]
      const shadeBottom = bands[i][1] + spec.shade

      const span = bottom - top

      const first = Math.max(0, Math.floor(top))
      const last = Math.min(height - 1, Math.ceil(bottom) - 1)

      for (let y = first; y <= last; y++) {
        // Fraction of this pixel row the band actually covers, so the boundary
        // between two bands is a blend rather than a staircase.
        const coverage = Math.min(y + 1, bottom) - Math.max(y, top)
        if (coverage <= 0) continue

        const t = shadeTop + (shadeBottom - shadeTop) * ((y + 0.5 - top) / span)
        const s = Math.min(RAMP_STEPS - 1, Math.max(0, Math.round(t * (RAMP_STEPS - 1)))) * 3

        surface.blend(x, y, ramp[s], ramp[s + 1], ramp[s + 2], coverage)
      }
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
