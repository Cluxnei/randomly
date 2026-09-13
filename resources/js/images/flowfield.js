/**
 * Particles let loose in a noise field.
 *
 *     θ(x, y) = fBm(x·s, y·s) · 2π · turns
 *     p      ← p + (cos θ, sin θ) · step
 *
 * Two lines, and the entire interest of the output is in what happens when a
 * thousand of them are drawn on top of each other at 8% opacity: paths that agree
 * accumulate into bright strands, paths that only pass through leave a wash. The
 * picture is a density map of a vector field, which is why it looks like
 * something photographed rather than something plotted.
 *
 * The Perlin implementation is imported from the Patterns module rather than
 * copied. It is the same noise, and two copies of a noise function is two chances
 * to have subtly different noise.
 */
import { fractal } from '../patterns/perlin.js'
import { Surface, hexToRgb, sampleRamp } from './raster.js'

const TAU = Math.PI * 2

/** Entries in the colour lookup table. 512 is well past what 8-bit output resolves. */
const RAMP_STEPS = 512

/**
 * How many bytes of stream this spec will consume.
 *
 * Each particle draws two floats for its start, one bounded integer for its
 * colour and one float for its jitter — 22 bytes plus whatever the rejection
 * sampler discards. 32 is that with room, and running out mid-render throws
 * rather than silently drawing a shorter picture.
 */
export function bytesNeeded (spec) {
  return 4096 + 32 * spec.particles
}

/**
 * A render in progress.
 *
 * Split out from render() so the landing page can grow the trails across frames
 * instead of freezing the tab for 60ms. Every particle's seed is drawn up front,
 * so the stream is consumed in the same order however the pass is stepped: a
 * paused, resumed and re-paused animation lands on the identical final image as
 * the one-shot render in the studio.
 */
class Pass {
  constructor (ctx, spec, rng) {
    this.ctx = ctx
    this.spec = spec
    this.surface = new Surface(spec.width, spec.height)

    const [br, bg, bb] = hexToRgb(spec.background)
    this.surface.fill(br, bg, bb)

    this.perm = rng.permutation()
    this.additive = Boolean(spec.glow)
    this.radius = spec.line / 2
    this.stride = spec.scale / Math.max(spec.width, spec.height)

    // A flat lookup table rather than interpolating per splat: this is the
    // innermost loop in the file and it runs a few hundred thousand times.
    this.ramp = new Float32Array(RAMP_STEPS * 3)
    const stops = spec.palette.map(hexToRgb)

    for (let i = 0; i < RAMP_STEPS; i++) {
      const [r, g, b] = sampleRamp(stops, i / (RAMP_STEPS - 1))
      this.ramp[i * 3] = r
      this.ramp[i * 3 + 1] = g
      this.ramp[i * 3 + 2] = b
    }

    const n = spec.particles
    this.x = new Float32Array(n)
    this.y = new Float32Array(n)
    this.u = new Float32Array(n)
    this.age = new Uint16Array(n)
    this.life = new Uint16Array(n)
    this.amp = new Float32Array(n)

    const last = Math.max(1, spec.palette.length - 1)

    for (let i = 0; i < n; i++) {
      this.x[i] = rng.float() * spec.width
      this.y[i] = rng.float() * spec.height
      this.u[i] = rng.intBetween(0, spec.palette.length - 1) / last

      // One draw shapes both how long a particle lives and how strongly it
      // draws, so short trails are also the faint ones. Uniform trails read as a
      // machine; this reads as brushwork.
      const jitter = rng.float()
      this.life[i] = Math.max(4, Math.round(spec.trail * (0.45 + 0.55 * jitter)))
      this.amp[i] = 0.55 + 0.75 * jitter
    }

    this.steps = 0
    this.remaining = n
  }

  get done () {
    return this.remaining === 0 || this.steps >= this.spec.trail
  }

  /** Advance every living particle by one step, drawing where it lands. */
  step () {
    const { spec, surface, perm } = this
    const [ox, oy] = spec.offset
    const turn = TAU * spec.turns
    const limitX = spec.width + 2
    const limitY = spec.height + 2

    let alive = 0

    for (let i = 0; i < spec.particles; i++) {
      const life = this.life[i]
      const age = this.age[i]

      if (age >= life) continue

      const fromX = this.x[i]
      const fromY = this.y[i]

      const theta = fractal(perm, fromX * this.stride + ox, fromY * this.stride + oy, spec) * turn
      const x = fromX + Math.cos(theta) * spec.step
      const y = fromY + Math.sin(theta) * spec.step

      this.x[i] = x
      this.y[i] = y
      this.age[i] = age + 1

      // Off the edge is the end of that particle. Wrapping would draw a line
      // across the canvas that the field never actually took.
      if (x < -2 || y < -2 || x > limitX || y > limitY) {
        this.age[i] = life
        continue
      }

      alive++

      const t = age / life

      // Taper both ends of the trail. sin gives a stroke that starts from
      // nothing and lands on nothing; the fractional power keeps it full
      // through the middle rather than diamond-shaped.
      const taper = Math.pow(Math.sin(Math.PI * t), 0.45)

      // Trails travel along the ramp as they age, so a strand is a gradient
      // rather than a coloured line — the clearest single reason the output
      // reads as depth.
      const shade = Math.min(RAMP_STEPS - 1, Math.max(0,
        Math.round((this.u[i] + (t - 0.5) * spec.drift) * (RAMP_STEPS - 1))
      )) * 3

      // The segment, not the endpoint. A particle moving 1.6px a step and
      // drawing a 1.1px dot leaves a dotted line; drawing the join makes it a
      // trail, and makes the step length a control over shape instead of over
      // how broken the line looks.
      surface.stroke(
        fromX, fromY, x, y,
        this.ramp[shade], this.ramp[shade + 1], this.ramp[shade + 2],
        spec.alpha * this.amp[i] * taper,
        this.radius,
        this.additive,
      )
    }

    this.steps++
    this.remaining = alive
  }

  /** Step until nothing is left moving. */
  run () {
    while (!this.done) this.step()
  }

  /** Push the accumulation buffer to the canvas. Safe to call every frame. */
  commit () {
    this.surface.commit(this.ctx, this.spec.grain, this.spec.grain_seed)
  }
}

export function createPass (ctx, spec, rng) {
  return new Pass(ctx, spec, rng)
}

export function render (ctx, spec, rng) {
  const pass = new Pass(ctx, spec, rng)
  pass.run()
  pass.commit()
}
