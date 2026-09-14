/**
 * Solve a Wave Function Collapse sample and audit the result.
 *
 * The guarantee the overlapping model makes is precise and checkable: every N×N
 * window of the output must be a window that appears somewhere in the input,
 * under the symmetries allowed. That is a stronger statement than "no two
 * neighbouring cells disagree", and it is the one the whole algorithm exists to
 * deliver — so it is measured here rather than asserted in prose, and
 * tests/Unit/StructuredPatternsTest.php asserts against what comes back.
 *
 * The set of legal windows is rebuilt here rather than imported from wfc.js.
 * Importing the extractor would test the solver against the extractor's idea of
 * the rules, which is exactly the pair of things that could be wrong together.
 *
 *   node scripts/check-wfc.mjs <rows,comma,separated> <n> <symmetry> <cols> <rows> <seed>
 */
import { solve } from '../resources/js/patterns/wfc.js'

const rows = process.argv[2].split(',')
const n = Number(process.argv[3])
const symmetry = Number(process.argv[4])
const cols = Number(process.argv[5])
const gridRows = Number(process.argv[6])
const seed = Number(process.argv[7])

const sampleCols = rows[0].length
const sampleRows = rows.length
const sample = []

for (const row of rows) for (const c of row) sample.push(Number(c))

/** The eight symmetries, written out independently of the renderer's version. */
function variants (tile) {
  const at = (x, y) => tile[y * n + x]
  const build = f => {
    const out = []
    for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) out.push(f(x, y))
    return out.join('')
  }

  const all = [
    build((x, y) => at(x, y)),
    build((x, y) => at(n - 1 - y, x)),
    build((x, y) => at(n - 1 - x, n - 1 - y)),
    build((x, y) => at(y, n - 1 - x)),
    build((x, y) => at(n - 1 - x, y)),
    build((x, y) => at(y, x)),
    build((x, y) => at(x, n - 1 - y)),
    build((x, y) => at(n - 1 - y, n - 1 - x)),
  ]

  // The renderer's symmetry counts index the same prefixes: 1 as drawn, 2 the
  // mirror pair, 4 the rotations, 8 everything.
  if (symmetry === 1) return [all[0]]
  if (symmetry === 2) return [all[0], all[4]]
  if (symmetry === 4) return all.slice(0, 4)

  return all
}

// Every window of the sample, read periodically, with its symmetries.
const legal = new Set()

for (let y = 0; y < sampleRows; y++) {
  for (let x = 0; x < sampleCols; x++) {
    const tile = []

    for (let dy = 0; dy < n; dy++) {
      for (let dx = 0; dx < n; dx++) {
        tile.push(sample[((y + dy) % sampleRows) * sampleCols + ((x + dx) % sampleCols)])
      }
    }

    for (const key of variants(tile)) legal.add(key)
  }
}

const result = solve({
  sample: { rows },
  n,
  symmetry,
  cols,
  rows: gridRows,
  seed,
})

let violations = 0
let windows = 0
const seen = new Set()

for (let y = 0; y + n <= gridRows; y++) {
  for (let x = 0; x + n <= cols; x++) {
    const window = []

    for (let dy = 0; dy < n; dy++) {
      for (let dx = 0; dx < n; dx++) {
        window.push(result.cells[(y + dy) * cols + (x + dx)])
      }
    }

    const key = window.join('')
    windows++
    seen.add(key)

    if (!legal.has(key)) violations++
  }
}

console.log(JSON.stringify({
  solved: result.solved,
  contradictions: result.contradictions,
  restarts: result.restarts,
  patterns: result.patterns.length,
  legal_windows: legal.size,
  windows,
  distinct_windows: seen.size,
  violations,
}))
