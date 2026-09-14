/**
 * Walk one panel of `patterns.walk` and report the distribution of its steps.
 *
 * The generator's claim is that the three modes are three different processes
 * rather than one process with three step sizes, and the difference lives
 * entirely in JavaScript — the PHP side ships a step scale and a seed, and the
 * walks themselves are expanded in the browser. So the claim is measured by
 * running the real renderer, and tests/Unit/MeasuredPatternsTest.php asserts on
 * what comes back.
 *
 * **The steps are read off the strokes the renderer actually draws.** Surface's
 * `stroke` is the one place every segment passes through, so replacing it with a
 * recorder captures the walk exactly as it is laid down — no second
 * implementation of the walk to agree with itself, and no private hook that a
 * refactor could leave pointing at nothing.
 *
 * The panel given to it is a long thin one, far larger than any the generator
 * would ever emit, and that is deliberate. `strokeClipped` cuts a segment at the
 * panel edge, which is right for a picture and fatal for this measurement: the
 * segments it would trim are the enormous Lévy jumps, which are the only thing
 * worth measuring. A panel nothing can leave keeps every step whole. The script
 * proves it by checking that each segment starts where the last one ended —
 * a clipped or dropped segment breaks that chain, and `breaks` would be non-zero.
 *
 *   node scripts/check-walk.mjs <mode> <walkers> <steps> <tail> <seed>
 */
import { Surface } from '../resources/js/images/raster.js'
import { render } from '../resources/js/patterns/walk.js'

const mode = process.argv[2]
const walkers = Number(process.argv[3])
const steps = Number(process.argv[4])
const tail = Number(process.argv[5])
const seed = Number(process.argv[6])

const segments = []

/*
 * The recorder. `fill` and `commit` go with it: neither contributes to the walk,
 * and between them they touch every pixel of a canvas this measurement wants to
 * be able to make absurdly large.
 */
Surface.prototype.stroke = function (x0, y0, x1, y1) {
  segments.push(x0, y0, x1, y1)
}
Surface.prototype.fill = function () {}
Surface.prototype.commit = function () {}

/**
 * A self-avoiding walk stays on its lattice, so its panel is sized to the
 * lattice exactly — every cell it can reach is inside the clip window, and the
 * chain of segments stays unbroken for the same reason the other two modes' does.
 */
const CELL = 4
const COLS = 200
const ROWS = 200

const saw = mode === 'saw'
const panel = saw
  ? { mode, x: 0, width: (COLS - 1) * CELL, step: CELL, walkers, steps, alpha: 0.35, cell: CELL, cols: COLS, rows: ROWS }
  : { mode, x: -50000, width: 100000, step: 1, walkers, steps, alpha: 0.1 }

render({}, {
  algorithm: 'walk',
  // Tall and narrow for the unbounded modes: the clip window's top and bottom
  // are the canvas, not the panel, so height is the only way to give a walk room
  // to leave and come back. Width stays small because the surface is allocated
  // from both, even though nothing is ever drawn into it.
  width: saw ? (COLS - 1) * CELL : 4,
  height: saw ? (ROWS - 1) * CELL : 400000,
  walkers,
  steps,
  tail,
  start: 'centre',
  alpha: 0.1,
  line: 1.2,
  glow: true,
  background: '#000000',
  rule: '#111111',
  palette: ['#000000', '#ffffff'],
  drift: 0.42,
  panels: [panel],
  grain: 0,
  grain_seed: 1,
  seed,
})

const count = segments.length / 4

/**
 * How many times a segment failed to start where the previous one ended.
 *
 * One per walker is expected and subtracted below — each new walker starts
 * somewhere fresh. Anything beyond that is a segment the clipper trimmed or
 * dropped, which would mean the lengths measured here are not the walk's.
 */
let breaks = 0
const lengths = new Float64Array(count)

for (let i = 0; i < count; i++) {
  const x0 = segments[i * 4]
  const y0 = segments[i * 4 + 1]

  if (i > 0 && (x0 !== segments[i * 4 - 2] || y0 !== segments[i * 4 - 1])) breaks++

  lengths[i] = Math.hypot(segments[i * 4 + 2] - x0, segments[i * 4 + 3] - y0)
}

const sorted = Float64Array.from(lengths).sort()
const median = count ? sorted[count >> 1] : 0
const longest = count ? sorted[count - 1] : 0
const total = lengths.reduce((a, b) => a + b, 0)

/**
 * Hill's estimator of the tail exponent, from the k largest steps.
 *
 * The maximum-likelihood estimate of α for a Pareto tail, and the standard tool
 * for the job: it uses only the top of the distribution, which is exactly the
 * part the `tail` slider is about and the part a bounded step length could never
 * produce. k is a compromise — too few and the estimate is noisy, too many and
 * it reaches down into the body of the distribution, where the power law does
 * not hold and the estimate biases upward.
 */
function hill (k) {
  if (count <= k) return null

  let sum = 0
  for (let i = 0; i < k; i++) sum += Math.log(sorted[count - 1 - i] / sorted[count - 1 - k])

  return k / sum
}

if (!saw) {
  console.log(JSON.stringify({
    segments: count,
    breaks: breaks - (walkers - 1),
    median,
    longest,
    // A step length distribution with a finite variance cannot produce a single
    // step hundreds of times the typical one; a Pareto tail below α = 2 does it
    // routinely. This is the whole difference between the two panels, in a ratio.
    longest_over_median: median ? longest / median : null,
    longest_share: total ? longest / total : null,
    tail_exponent: hill(2000),
  }))
} else {
  /*
   * Split the stream back into walks and check each one for a repeat.
   *
   * Lattice coordinates are recovered from the drawn positions rather than from
   * the walk's own `visited` grid — which is the array the self-avoidance is
   * enforced with, and therefore the last thing that should be asked whether the
   * self-avoidance held.
   */
  const walks = []
  let current = null

  for (let i = 0; i < count; i++) {
    const x0 = segments[i * 4]
    const y0 = segments[i * 4 + 1]

    if (!current || x0 !== current.x || y0 !== current.y) {
      current = { x: x0, y: y0, cells: [[Math.round(x0 / CELL), Math.round(y0 / CELL)]] }
      walks.push(current)
    }

    current.x = segments[i * 4 + 2]
    current.y = segments[i * 4 + 3]
    current.cells.push([Math.round(current.x / CELL), Math.round(current.y / CELL)])
  }

  let revisits = 0
  let offLattice = 0
  let longestWalk = 0
  let cells = 0

  for (const walk of walks) {
    const seen = new Set()

    for (const [gx, gy] of walk.cells) {
      if (gx < 0 || gx >= COLS || gy < 0 || gy >= ROWS) offLattice++

      const key = gy * COLS + gx
      if (seen.has(key)) revisits++
      seen.add(key)
    }

    if (walk.cells.length > longestWalk) longestWalk = walk.cells.length
    cells += walk.cells.length
  }

  console.log(JSON.stringify({
    segments: count,
    // Two walkers that happened to start on the same cell the last one ended on
    // would be spliced into one walk here and could then look like a revisit, so
    // the count is reported and the test insists it matches the walkers asked for.
    walks: walks.length,
    revisits,
    off_lattice: offLattice,
    // Every step is one lattice cell, so a length that is not the cell size means
    // the walk left the lattice or the clipper trimmed it.
    off_pitch: [...lengths].filter(l => Math.abs(l - CELL) > 1e-9).length,
    mean_length: walks.length ? cells / walks.length : 0,
    longest_walk: longestWalk,
  }))
}
