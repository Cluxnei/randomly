/**
 * Run a Life-like rule over a known starting position and report what happened.
 *
 * The evolution lives in the browser — a 300×200 grid over 240 generations is
 * eighteen million cell updates and nothing anyone would ship as a spec — so the
 * only way to test it from PHP is to run the real thing. This calls the exact
 * `step` the renderer calls; a second implementation written for the test would
 * agree with itself and prove nothing.
 *
 * The positions checked are the ones with known answers. A block is a still life
 * and must be identical forever; a blinker has period two and must return to
 * itself on every even generation and never on an odd one; a glider returns to
 * itself displaced by (1, 1) after four generations, which is the only one of the
 * three that tests the rule's *asymmetry* rather than just its arithmetic.
 *
 *   node scripts/check-life.mjs <notation-birth-digits> <survive-digits> <shape> <generations>
 */
import { step } from '../resources/js/patterns/life.js'

const [birthDigits, surviveDigits, shape, generations] = [
  process.argv[2], process.argv[3], process.argv[4], Number(process.argv[5]),
]

/** Starting positions, on a torus big enough that nothing meets its own wake. */
const SHAPES = {
  block: { size: 12, cells: [[5, 5], [6, 5], [5, 6], [6, 6]] },
  blinker: { size: 12, cells: [[5, 6], [6, 6], [7, 6]] },
  glider: { size: 24, cells: [[2, 1], [3, 2], [1, 3], [2, 3], [3, 3]] },
  beehive: { size: 12, cells: [[5, 4], [6, 4], [4, 5], [7, 5], [5, 6], [6, 6]] },
}

function table (digits) {
  const out = new Array(9).fill(0)
  for (const d of digits) out[Number(d)] = 1
  return out
}

const { size, cells } = SHAPES[shape]
const birth = table(birthDigits)
const survive = table(surviveDigits)

// Birth on zero neighbours is suppressed by the generator for the same reason it
// is suppressed here: on a torus it lights every empty cell at once, forever.
birth[0] = 0

let state = new Uint8Array(size * size)
let next = new Uint8Array(size * size)

for (const [x, y] of cells) state[y * size + x] = 1

const initial = state.join('')
const history = []

for (let g = 0; g < generations; g++) {
  step(state, next, size, size, birth, survive)
  const swap = state
  state = next
  next = swap

  history.push(state.join('') === initial)
}

/** Where the live cells sit, as a bounding-box offset — for the glider. */
function corner (grid) {
  let minX = size
  let minY = size

  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (!grid[y * size + x]) continue
      if (x < minX) minX = x
      if (y < minY) minY = y
    }
  }

  return [minX, minY]
}

let population = 0
for (let i = 0; i < state.length; i++) population += state[i]

console.log(JSON.stringify({
  // Which generations returned the position to its exact starting state.
  returned: history,
  period: history.indexOf(true) + 1 || null,
  population,
  start_corner: corner(Uint8Array.from(initial.split('').map(Number))),
  end_corner: corner(state),
}))
