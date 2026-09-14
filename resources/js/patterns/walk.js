/**
 * Three random walks, drawn side by side so the difference between them is
 * something you can see rather than something you have to be told.
 *
 *   Brownian      step ~ N(0, σ) in each axis
 *   Lévy flight   direction ~ U(0, 2π),  length ~ Pareto(α):  ℓ = σ·U^(−1/α)
 *   Self-avoiding lattice step, uniform over the neighbours not yet visited
 *
 * The Lévy flight is the reason this generator exists. A Pareto step length with
 * α below 2 has infinite variance, so the walk is dominated by its rare enormous
 * jumps: it mills about in one place, then crosses the whole frame in a single
 * segment, then mills again. That is the shape an albatross searches an ocean in,
 * and it looks nothing like the even fuzz of a Brownian cloud drawn from the same
 * number of steps. Put the two next to each other and nobody needs the word
 * "superdiffusive" explained to them.
 *
 * Everything is drawn through the software rasteriser in images/raster.js, for
 * the reason its header gives: a few hundred thousand near-transparent segments
 * accumulated in floats keep contributions that 8-bit canvas compositing would
 * round away on the way in.
 */
import { Surface, hexToRgb, sampleRamp } from '../images/raster.js'
import { splitmix32 } from './prng.js'

const TAU = Math.PI * 2

/** Entries in the colour lookup table. Past what 8-bit output can resolve. */
const RAMP_STEPS = 512

/**
 * Longest single Lévy jump, as a multiple of the canvas diagonal.
 *
 * Pareto has no upper bound, so without a cap one draw in a few thousand is a
 * segment a hundred canvases long. It is not wrong — that is what a heavy tail
 * *is* — but the rasteriser then lays down a hundred canvases' worth of dots to
 * produce what appears as one straight line, and near α = 1 that happens often
 * enough to stall the render. Two diagonals changes no picture and bounds the
 * work.
 */
const MAX_JUMP = 2

/**
 * Draw a segment, clipped to one panel.
 *
 * Liang–Barsky, because the alternative spellings are both wrong here. Dropping
 * any segment with an endpoint outside the panel would delete exactly the long
 * Lévy jumps the comparison is *about*; letting them run unclipped is what the
 * first render did, and the three panels wrote over each other into one
 * illegible tangle. Clipping the segment and drawing the part that is inside
 * keeps the jump visible as a line leaving the panel, which is the truth.
 *
 * The parametric form is worth the six lines: it clips against all four edges
 * with one pass of the same two-line test, and the walk carries on from its real
 * position rather than from the clipped one.
 */
function strokeClipped (surface, x0, y0, x1, y1, left, right, bottom, r, g, b, alpha, radius, additive) {
  const dx = x1 - x0
  const dy = y1 - y0

  let t0 = 0
  let t1 = 1

  // p is the component of the segment across each edge, q the distance to it.
  // A zero p means the segment is parallel to that edge, in which case a
  // negative q puts it wholly outside and there is nothing to draw.
  const p = [-dx, dx, -dy, dy]
  const q = [x0 - left, right - x0, y0, bottom - y0]

  for (let i = 0; i < 4; i++) {
    if (p[i] === 0) {
      if (q[i] < 0) return
      continue
    }

    const t = q[i] / p[i]

    if (p[i] < 0) {
      if (t > t1) return
      if (t > t0) t0 = t
    } else {
      if (t < t0) return
      if (t < t1) t1 = t
    }
  }

  surface.stroke(
    x0 + dx * t0, y0 + dy * t0,
    x0 + dx * t1, y0 + dy * t1,
    r, g, b, alpha, radius, additive,
  )
}

/** Box–Muller, taking both halves of the pair so no draw is wasted. */
function gaussianPair (random, out) {
  const u1 = 1 - random()
  const u2 = random()
  const r = Math.sqrt(-2 * Math.log(u1))

  out[0] = r * Math.cos(TAU * u2)
  out[1] = r * Math.sin(TAU * u2)
}

/**
 * Walk one panel.
 *
 * A panel carries its own width and its own step scale, because comparison mode
 * gives each of the three a third of the canvas and a walk scaled for the full
 * width would spill straight out of it. Returns what was actually drawn — a
 * self-avoiding walk cannot promise to reach the requested length, and how often
 * it fails to is worth reporting rather than hiding.
 */
function walkPanel (surface, spec, panel, random, ramp) {
  const walkers = panel.walkers
  const steps = panel.steps
  const additive = Boolean(spec.glow)
  const radius = spec.line / 2
  const maxJump = MAX_JUMP * Math.hypot(panel.width, spec.height)

  /*
   * Self-avoiding walks need to remember where they have been, and remember it
   * per walker: a walk that also avoided the other walkers' cells would be a
   * different process with different statistics, however similar the picture.
   *
   * Storing the walker's own number rather than a boolean is what makes "forget
   * everything" free. Clearing a grid of a hundred thousand cells between two
   * hundred walkers would cost more than the walks do.
   */
  const visited = panel.mode === 'saw' ? new Int32Array(panel.cols * panel.rows) : null
  const stamp = w => w + 1

  const pair = new Float64Array(2)
  const left = panel.x
  const right = panel.x + panel.width
  const cx = left + panel.width / 2
  const cy = spec.height / 2

  let drawn = 0
  let trapped = 0

  for (let w = 0; w < walkers; w++) {
    // One draw fixes where this walker starts along the ramp, so each trail is a
    // distinct colour that then drifts as it ages.
    const base = random()

    let x = spec.start === 'centre' ? cx : left + random() * panel.width
    let y = spec.start === 'centre' ? cy : random() * spec.height

    let gx = 0
    let gy = 0

    if (visited) {
      // A self-avoiding walk traps itself after about seventy steps and then has
      // nothing more to say about where it began, so these always scatter: a
      // hundred of them stacked on one centre cell would be one walk and
      // ninety-nine that trapped on step one.
      gx = Math.min(panel.cols - 1, (random() * panel.cols) | 0)
      gy = Math.min(panel.rows - 1, (random() * panel.rows) | 0)
      visited[gy * panel.cols + gx] = stamp(w)
      x = left + gx * panel.cell
      y = gy * panel.cell
    }

    for (let s = 0; s < steps; s++) {
      let nx
      let ny

      if (panel.mode === 'saw') {
        /*
         * Uniform over the neighbours not yet visited.
         *
         * That is the definition of the self-avoiding walk as a process, and it
         * is *not* the same distribution as "a Brownian walk conditioned on
         * never crossing itself" — the two differ, famously, and this is the
         * one that can be sampled in linear time.
         */
        let count = 0
        const options = [0, 0, 0, 0]

        // Compared against this walker's own stamp, not against zero. Testing
        // for "any mark at all" would make every walk avoid every earlier walk
        // as well as itself, which is a different process — and in practice a
        // fatal one: the second walker inherits a board the first has already
        // covered and traps within a few steps.
        const mine = stamp(w)

        if (gx > 0 && visited[gy * panel.cols + gx - 1] !== mine) options[count++] = 0
        if (gx < panel.cols - 1 && visited[gy * panel.cols + gx + 1] !== mine) options[count++] = 1
        if (gy > 0 && visited[(gy - 1) * panel.cols + gx] !== mine) options[count++] = 2
        if (gy < panel.rows - 1 && visited[(gy + 1) * panel.cols + gx] !== mine) options[count++] = 3

        // Trapped: every neighbour is off the grid or already used. The walk
        // ends there, which is the whole reason a self-avoiding walk of a
        // requested length may simply not exist.
        if (count === 0) {
          trapped++
          break
        }

        const move = options[Math.min(count - 1, (random() * count) | 0)]
        if (move === 0) gx--
        else if (move === 1) gx++
        else if (move === 2) gy--
        else gy++

        visited[gy * panel.cols + gx] = stamp(w)
        nx = left + gx * panel.cell
        ny = gy * panel.cell
      } else if (panel.mode === 'levy') {
        // Inverse-transform sampling of a Pareto: if U ~ Uniform(0, 1] then
        // U^(−1/α) has tail exponent α. One draw, no rejection.
        const theta = random() * TAU
        const length = Math.min(maxJump, panel.step * Math.pow(1 - random(), -1 / spec.tail))

        nx = x + Math.cos(theta) * length
        ny = y + Math.sin(theta) * length
      } else {
        gaussianPair(random, pair)
        nx = x + pair[0] * panel.step
        ny = y + pair[1] * panel.step
      }

      const t = s / steps

      // Trails travel along the ramp as they age, so a walk reads as a path with
      // a beginning and an end rather than as a coloured tangle.
      const shade = Math.min(RAMP_STEPS - 1, Math.max(0,
        Math.round((base + (t - 0.5) * spec.drift) * (RAMP_STEPS - 1))
      )) * 3

      strokeClipped(
        surface, x, y, nx, ny,
        left, right, spec.height,
        ramp[shade], ramp[shade + 1], ramp[shade + 2],
        panel.alpha,
        radius,
        additive,
      )

      drawn++
      x = nx
      y = ny

      // Far enough outside that the odds of coming back inside the remaining
      // steps are negligible, and the walk is abandoned rather than tracked for
      // nothing. Deliberately generous: a Lévy flight really does leave and
      // return, and cutting it at the panel edge would delete the behaviour the
      // panel is there to show.
      if (x < left - 2 * panel.width || y < -2 * spec.height || x > right + 2 * panel.width || y > 3 * spec.height) break
    }
  }

  return { drawn, trapped }
}

export function render (ctx, spec) {
  const { width, height, palette } = spec
  const random = splitmix32(spec.seed >>> 0)

  const surface = new Surface(width, height)
  const [br, bg, bb] = hexToRgb(spec.background)
  surface.fill(br, bg, bb)

  // A flat lookup table rather than interpolating per segment: this is the
  // innermost loop and it runs a few hundred thousand times.
  const ramp = new Float32Array(RAMP_STEPS * 3)
  const stops = palette.map(hexToRgb)

  for (let i = 0; i < RAMP_STEPS; i++) {
    const [r, g, b] = sampleRamp(stops, i / (RAMP_STEPS - 1))
    ramp[i * 3] = r
    ramp[i * 3 + 1] = g
    ramp[i * 3 + 2] = b
  }

  for (const panel of spec.panels) {
    walkPanel(surface, spec, panel, random, ramp)
  }

  if (spec.panels.length > 1) {
    /*
     * A one-pixel rule between panels, in the ground colour darkened.
     *
     * Drawn into the accumulation buffer rather than over the finished pixels:
     * `commit` owns the only putImageData here, and the render-preview shim
     * keeps exactly one ImageData, so a second write would replace the picture
     * rather than overlay it.
     */
    const [rr, rg, rb] = hexToRgb(spec.rule)

    for (let i = 1; i < spec.panels.length; i++) {
      const x = Math.round(spec.panels[i].x) - 1

      for (let y = 0; y < height; y++) {
        surface.blend(x, y, rr, rg, rb, 1)
        surface.blend(x + 1, y, rr, rg, rb, 1)
      }
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
