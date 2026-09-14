/**
 * A weighted particle spray, drawn from a Gaussian mixture.
 *
 *   pick component i with probability wᵢ
 *   z ~ N(0, I)              two standard normals
 *   p = μᵢ + Rᵢ Sᵢ z         rotate and scale into the component's own ellipse
 *
 * A mixture rather than a single Gaussian, because one Gaussian is a fuzzy dot
 * and a photograph of nothing. Several overlapping anisotropic ones read as
 * depth: the eye resolves the dense cores as objects and the overlapping fringes
 * as the space between them, and neither is drawn.
 *
 * The whole picture is built out of the *density* of hundreds of thousands of
 * near-invisible marks — the same principle as the flow field, one dimension
 * simpler. That is why the dots are laid down additively at an opacity of a
 * percent or two: where two components overlap, their light adds, and the
 * brightness map of the result is the mixture's own probability density function.
 * Turn the opacity up and it collapses into flat paint.
 */
import { splitmix32 } from '../patterns/prng.js'
import { Surface, hexToRgb, sampleRamp } from './raster.js'

const TAU = Math.PI * 2

/** Entries in the colour lookup table. Past what 8-bit output can resolve. */
const RAMP_STEPS = 512

/** Nothing is read from the stream; particles expand from the spec's seed. */
export function bytesNeeded () {
  return 1024
}

export function render (ctx, spec) {
  const { width, height, components, particles, palette } = spec
  const random = splitmix32(spec.seed >>> 0)

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

  const n = components.length

  // Unpacked into flat arrays up front. The inner loop runs a few hundred
  // thousand times and reading five fields off an object each pass is measurably
  // slower than five typed-array loads.
  const mx = new Float64Array(n)
  const my = new Float64Array(n)
  const ax = new Float64Array(n)
  const ay = new Float64Array(n)
  const bx = new Float64Array(n)
  const by = new Float64Array(n)
  const shade = new Float64Array(n)
  const cumulative = new Float64Array(n)

  let total = 0

  for (let i = 0; i < n; i++) {
    const [cxf, cyf, sigmaA, sigmaB, angle, weight, colour] = components[i]

    mx[i] = cxf * width
    my[i] = cyf * height

    // The two principal axes of the component's ellipse, already rotated. Storing
    // them this way turns the transform into two multiply-adds per particle
    // instead of a matrix build and a matrix multiply.
    const scale = Math.min(width, height)
    const cos = Math.cos(angle)
    const sin = Math.sin(angle)

    ax[i] = cos * sigmaA * scale
    ay[i] = sin * sigmaA * scale
    bx[i] = -sin * sigmaB * scale
    by[i] = cos * sigmaB * scale

    shade[i] = colour / Math.max(1, palette.length - 1)

    total += weight
    cumulative[i] = total
  }

  const radius = spec.radius
  const alpha = spec.alpha
  const additive = Boolean(spec.glow)
  const drift = spec.drift

  for (let p = 0; p < particles; p++) {
    // Linear scan over the cumulative weights. n is under ten, so a binary
    // search or an alias table would be slower than the branch it replaced.
    const target = random() * total
    let k = n - 1
    for (let i = 0; i < n; i++) {
      if (target < cumulative[i]) { k = i; break }
    }

    // Box–Muller, both halves used: one standard normal per axis is exactly
    // what the transform below wants.
    const u1 = 1 - random()
    const u2 = random()
    const r = Math.sqrt(-2 * Math.log(u1))
    const z0 = r * Math.cos(TAU * u2)
    const z1 = r * Math.sin(TAU * u2)

    const x = mx[k] + ax[k] * z0 + bx[k] * z1
    const y = my[k] + ay[k] * z0 + by[k] * z1

    if (x < -radius || y < -radius || x > width + radius || y > height + radius) continue

    /*
     * Colour drifts outwards from each component's own stop with the
     * Mahalanobis radius, so a core is one colour and its halo another.
     *
     * z0² + z1² is that radius squared, and it is already in hand — computing
     * the distance in pixels instead would need a square root per particle and
     * would measure the wrong thing, since an elongated component's fringe is
     * much further away along one axis than the other.
     */
    const t = shade[k] + Math.sqrt(z0 * z0 + z1 * z1) * drift
    const s = Math.min(RAMP_STEPS - 1, Math.max(0, Math.round(t * (RAMP_STEPS - 1)))) * 3

    surface.splat(x, y, ramp[s], ramp[s + 1], ramp[s + 2], alpha, radius, additive)
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
