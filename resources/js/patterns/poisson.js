/**
 * Bridson's Poisson-disk sampling, drawn beside the uniform random points it is
 * so much better than.
 *
 * The algorithm is short and the background grid is the whole trick. Cells of
 * side r/√2 have a diagonal of exactly r, so a cell can never hold two points that
 * satisfy the separation rule — at most one point per cell. Checking a candidate
 * therefore means looking at a fixed 5×5 block of cells rather than at every point
 * already placed, and the sampler runs in linear time instead of quadratic.
 *
 *   1. Drop one point anywhere; put it on the active list.
 *   2. Take a random active point. Try k candidates in the annulus [r, 2r) around
 *      it. Accept the first that is in bounds and no closer than r to anything.
 *   3. A point that survives k failures can have no more neighbours: retire it.
 *   4. Stop when nothing is active.
 *
 * The annulus is the part people get wrong. Sampling the radius uniformly in
 * [r, 2r) over-weights the inner ring — area grows as ρ dρ — so the correct draw
 * is ρ = √(r² + u(4r² − r²)), which is the same inverse-CDF correction the Earth
 * points generator makes for latitude. It is not a large effect here, but getting
 * it wrong in a generator whose neighbour teaches exactly this would be poor form.
 */
import { Surface } from '../images/raster.js'
import { rampAt, shade, toRgb } from './colour.js'
import { splitmix32 } from './prng.js'

/**
 * @returns {Float64Array} interleaved x, y pairs — one flat array rather than an
 * array of pairs, because a dense field is tens of thousands of points and that
 * many two-element arrays is tens of thousands of allocations.
 */
export function bridson (random, width, height, r, k) {
  const cell = r / Math.SQRT2
  const cols = Math.ceil(width / cell)
  const rows = Math.ceil(height / cell)

  const grid = new Int32Array(cols * rows).fill(-1)
  const points = []
  const active = []

  const rr = r * r

  function fits (x, y) {
    if (x < 0 || y < 0 || x >= width || y >= height) return false

    const cx = (x / cell) | 0
    const cy = (y / cell) | 0

    // Two rings, not one. A point in the cell diagonally two away can still be
    // within r, because r spans two cells; checking 3×3 leaves pairs that
    // violate the separation and the whole guarantee quietly stops holding.
    const x0 = Math.max(0, cx - 2)
    const x1 = Math.min(cols - 1, cx + 2)
    const y0 = Math.max(0, cy - 2)
    const y1 = Math.min(rows - 1, cy + 2)

    for (let gy = y0; gy <= y1; gy++) {
      for (let gx = x0; gx <= x1; gx++) {
        const i = grid[gy * cols + gx]
        if (i < 0) continue

        const dx = points[i * 2] - x
        const dy = points[i * 2 + 1] - y
        if (dx * dx + dy * dy < rr) return false
      }
    }

    return true
  }

  function add (x, y) {
    const index = points.length / 2
    points.push(x, y)
    grid[((y / cell) | 0) * cols + ((x / cell) | 0)] = index
    active.push(index)
  }

  add(random() * width, random() * height)

  while (active.length > 0) {
    const slot = Math.min(active.length - 1, (random() * active.length) | 0)
    const from = active[slot]
    const px = points[from * 2]
    const py = points[from * 2 + 1]

    let placed = false

    for (let attempt = 0; attempt < k; attempt++) {
      const angle = random() * Math.PI * 2
      // Uniform by area across the annulus, not uniform in radius.
      const rho = Math.sqrt(rr + random() * 3 * rr)
      const x = px + Math.cos(angle) * rho
      const y = py + Math.sin(angle) * rho

      if (fits(x, y)) {
        add(x, y)
        placed = true
        break
      }
    }

    if (!placed) {
      // Swap-pop: order in the active list carries no meaning, and splice() on a
      // list this long is the difference between linear and quadratic.
      active[slot] = active[active.length - 1]
      active.pop()
    }
  }

  return Float64Array.from(points)
}

/** The same number of points, with no separation promise whatsoever. */
export function scatter (random, width, height, count) {
  const points = new Float64Array(count * 2)

  for (let i = 0; i < count; i++) {
    points[i * 2] = random() * width
    points[i * 2 + 1] = random() * height
  }

  return points
}

/**
 * Distance from each point to its nearest neighbour, through the same background
 * grid the sampler used.
 *
 * This is the measurement that separates the two panels. Blue noise puts every
 * one of these just above r and almost nothing above 1.5r; uniform random spreads
 * them from nearly zero to several r. Colouring by it means the right-hand panel
 * is visibly mottled and the left-hand one is visibly not.
 */
export function nearestNeighbours (points, width, height, cell) {
  const count = points.length / 2
  const cols = Math.max(1, Math.ceil(width / cell))
  const rows = Math.max(1, Math.ceil(height / cell))

  // Buckets as a list per cell: unlike the sampler's grid, several uniform points
  // can share a cell, so one slot each will not do.
  const buckets = Array.from({ length: cols * rows }, () => [])

  for (let i = 0; i < count; i++) {
    const cx = Math.min(cols - 1, Math.max(0, (points[i * 2] / cell) | 0))
    const cy = Math.min(rows - 1, Math.max(0, (points[i * 2 + 1] / cell) | 0))
    buckets[cy * cols + cx].push(i)
  }

  const out = new Float64Array(count)

  for (let i = 0; i < count; i++) {
    const x = points[i * 2]
    const y = points[i * 2 + 1]
    const cx = Math.min(cols - 1, Math.max(0, (x / cell) | 0))
    const cy = Math.min(rows - 1, Math.max(0, (y / cell) | 0))

    let best = Infinity

    // Rings outwards, stopping once the ring itself is further away than the best
    // found. An unconditional fixed window would miss the nearest neighbour of an
    // isolated point in the uniform panel, which is exactly the interesting case.
    for (let ring = 0; ring < cols + rows; ring++) {
      const reach = (ring - 1) * cell
      if (ring > 0 && reach * reach > best) break

      for (let gy = cy - ring; gy <= cy + ring; gy++) {
        if (gy < 0 || gy >= rows) continue

        for (let gx = cx - ring; gx <= cx + ring; gx++) {
          // Only the perimeter of the ring is new; the interior was done already.
          if (gx < 0 || gx >= cols) continue
          if (ring > 0 && Math.abs(gx - cx) !== ring && Math.abs(gy - cy) !== ring) continue

          for (const j of buckets[gy * cols + gx]) {
            if (j === i) continue
            const dx = points[j * 2] - x
            const dy = points[j * 2 + 1] - y
            const d = dx * dx + dy * dy
            if (d < best) best = d
          }
        }
      }
    }

    out[i] = best === Infinity ? 0 : Math.sqrt(best)
  }

  return out
}

export function render (ctx, spec, rng) {
  const { width, height, radius, gutter, dot, mode } = spec

  const random = splitmix32(spec.seed >>> 0)
  const colours = toRgb(spec.palette)
  const ground = shade(colours[0], 0.4)
  // A frame rather than a lighter ground behind each panel. Every perceptual ramp
  // starts at something close to black — magma's first stop is #000004 — so a
  // panel painted a shade above the darkest colour is not a shade above anything.
  // A hairline drawn from the middle of the ramp is visible whatever the palette.
  const frame = rampAt(colours, 0.5, [0, 0, 0])
  const flat = spec.colouring !== 'nearest'

  const count = mode === 'compare' ? 2 : 1
  const panelWidth = (width - gutter * (count + 1)) / count
  const panelHeight = height - gutter * 2

  // Blue noise first even when only the uniform panel is shown, so that the two
  // modes hold the same number of points and switching between them compares
  // like with like.
  const blue = bridson(random, panelWidth, panelHeight, radius, spec.candidates)
  const uniform = scatter(random, panelWidth, panelHeight, blue.length / 2)

  const panels = mode === 'compare' ? [blue, uniform] : [mode === 'blue' ? blue : uniform]

  const surface = new Surface(width, height)
  surface.fill(ground[0], ground[1], ground[2])

  const rgb = [0, 0, 0]

  panels.forEach((points, index) => {
    const x0 = gutter + index * (panelWidth + gutter)

    for (let x = -1; x <= panelWidth; x++) {
      surface.blend((x0 + x) | 0, gutter - 1, frame[0], frame[1], frame[2], 0.3)
      surface.blend((x0 + x) | 0, (gutter + panelHeight) | 0, frame[0], frame[1], frame[2], 0.3)
    }

    for (let y = -1; y <= panelHeight; y++) {
      surface.blend((x0 - 1) | 0, (gutter + y) | 0, frame[0], frame[1], frame[2], 0.3)
      surface.blend((x0 + panelWidth) | 0, (gutter + y) | 0, frame[0], frame[1], frame[2], 0.3)
    }

    const near = flat ? null : nearestNeighbours(points, panelWidth, panelHeight, radius)

    for (let i = 0; i < points.length / 2; i++) {
      let t = 0.72

      if (near) {
        // A fixed scale in units of r, deliberately, where a field renderer would
        // stretch min-to-max. The absolute number is the lesson: every blue-noise
        // dot lands above 0.5 by construction and they are all nearly the same
        // colour, while the uniform panel uses the entire ramp. Normalising each
        // panel to its own range would hide precisely that.
        t = Math.min(1, near[i] / (2 * radius))
      }

      rampAt(colours, 0.22 + 0.78 * t, rgb)
      surface.splat(x0 + points[i * 2], gutter + points[i * 2 + 1], rgb[0], rgb[1], rgb[2], 1, dot, false)
    }
  })

  surface.commit(ctx)
}
