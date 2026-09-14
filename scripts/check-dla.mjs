/**
 * Grow a diffusion-limited aggregation cluster and audit its shape.
 *
 * Two things are true of a DLA cluster by construction and neither is visible in
 * a screenshot. It is **connected**: a particle freezes only where it touches
 * something already frozen, so every cell must reach every other. And it is
 * **fractal**: its radius grows as N^(1/D) with D around 1.71, which means a
 * cluster of eight times the particles is a little over three times as wide, not
 * eight times. tests/Unit/MeasuredPatternsTest.php asserts on both.
 *
 * The second one is not decoration. The generator's own comments record a real
 * failure where launching particles from half the *long* side of a landscape
 * lattice grew stringy diagonal arms at D ≈ 1.54 — a picture that still looked
 * like a dendrite, and was the wrong one. Nothing but a measurement catches that.
 *
 * **The cells are read off the splats the renderer draws**, not out of its
 * occupancy grid. That grid is the thing the walk tests against to decide where
 * to stick; asking it afterwards whether everything is connected would be asking
 * the algorithm to mark its own work. What is measured here is what ends up on
 * the canvas.
 *
 * Lattice coordinates have to be recovered, because the cluster is fitted to the
 * frame before it is drawn — a rescaling that would otherwise hide the whole
 * effect, since a cluster fitted to the canvas is the same size whatever N is.
 * The pitch comes back out of the drawn positions: the cluster is connected, so
 * every column between its leftmost and rightmost cell is occupied, so the
 * smallest gap between two distinct x positions is exactly one cell.
 *
 *   node scripts/check-dla.mjs <lattice> <seed> <particles,particles,...>
 */
import { Surface } from '../resources/js/images/raster.js'
import { render } from '../resources/js/patterns/dla.js'

const lattice = Number(process.argv[2])
const seed = Number(process.argv[3])
const counts = process.argv[4].split(',').map(Number)

const drawn = []

Surface.prototype.splat = function (x, y) {
  drawn.push(x, y)
}
Surface.prototype.fill = function () {}
Surface.prototype.commit = function () {}

/** Every cell of the aggregate, in lattice coordinates, from what was drawn. */
function latticeCells () {
  const count = drawn.length / 2
  const xs = [...new Set(drawn.filter((_, i) => i % 2 === 0))].sort((a, b) => a - b)

  let pitch = Infinity
  for (let i = 1; i < xs.length; i++) {
    const gap = xs[i] - xs[i - 1]
    if (gap > 1e-9 && gap < pitch) pitch = gap
  }

  let minX = Infinity
  let minY = Infinity
  for (let i = 0; i < count; i++) {
    if (drawn[i * 2] < minX) minX = drawn[i * 2]
    if (drawn[i * 2 + 1] < minY) minY = drawn[i * 2 + 1]
  }

  const cells = []
  for (let i = 0; i < count; i++) {
    cells.push([
      Math.round((drawn[i * 2] - minX) / pitch),
      Math.round((drawn[i * 2 + 1] - minY) / pitch),
    ])
  }

  return cells
}

/** Cells reachable from the first one by eight-way adjacency. */
function reachable (cells) {
  const key = ([x, y]) => x * 1000000 + y
  const all = new Set(cells.map(key))
  const seen = new Set([key(cells[0])])
  const stack = [cells[0]]

  while (stack.length) {
    const [x, y] = stack.pop()

    for (let dy = -1; dy <= 1; dy++) {
      for (let dx = -1; dx <= 1; dx++) {
        if (dx === 0 && dy === 0) continue

        const k = key([x + dx, y + dy])
        if (all.has(k) && !seen.has(k)) {
          seen.add(k)
          stack.push([x + dx, y + dy])
        }
      }
    }
  }

  return { reached: seen.size, total: all.size }
}

const results = []

for (const particles of counts) {
  drawn.length = 0

  const { placed } = render({}, {
    algorithm: 'dla',
    width: lattice,
    height: lattice,
    cols: lattice,
    rows: lattice,
    particles,
    seed_shape: 'point',
    stickiness: 1,
    // No halo: it is a second splat per cell, and the recorder cannot tell the
    // two apart.
    glow: 0,
    background: '#000000',
    palette: ['#000000', '#ffffff'],
    grain: 0,
    grain_seed: 1,
    seed,
  })

  const cells = latticeCells()
  const { reached, total } = reachable(cells)

  const cx = cells.reduce((a, c) => a + c[0], 0) / cells.length
  const cy = cells.reduce((a, c) => a + c[1], 0) / cells.length
  const spread = cells.reduce((a, c) => a + (c[0] - cx) ** 2 + (c[1] - cy) ** 2, 0) / cells.length

  results.push({
    // A run that stopped early — the cluster reached the edge of the board —
    // says nothing about how a cluster of the requested size would have scaled,
    // so the asked-for and achieved counts are both reported.
    asked: particles,
    placed,
    cells: total,
    reached,
    // In lattice cells, so the numbers from two different runs are comparable.
    // The drawn cluster is fitted to the frame, which makes canvas units useless
    // for this.
    radius_of_gyration: Math.sqrt(spread),
  })
}

console.log(JSON.stringify(results))
