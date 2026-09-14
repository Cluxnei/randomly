/**
 * Diffusion-limited aggregation: a crystal grown one sticky particle at a time.
 *
 * Release a particle far from the cluster, let it random-walk until it touches,
 * freeze it there, repeat. That is the whole rule, and out of it comes the
 * branching dendrite that copper deposits, frost on a window, lightning, coral
 * and a bacterial colony all converge on — a fractal of dimension about 1.71 in
 * the plane, which nobody put in.
 *
 * The reason it branches is worth stating, because it is not obvious and it is
 * the entire content of the picture: a wandering particle is far more likely to
 * meet a tip than a fjord, because to reach the fjord it would have to walk past
 * the tips on either side without touching them. Any protrusion therefore
 * captures more than its share of arrivals and grows faster, and the advantage
 * compounds. The cluster is a record of a screening effect.
 *
 * **Naive DLA is unusably slow and this one is not.** The cost is in the walking,
 * not the sticking, and almost all of that walking happens in the empty space
 * outside the cluster where nothing can be hit. So a particle that is d cells
 * clear of anything takes a single step of nearly d: it must land somewhere on a
 * circle of radius d about where it stood, and where on that circle is exactly
 * what a uniform angle gives. The walk is statistically unchanged and the render
 * is two orders of magnitude faster.
 */
import { Surface, hexToRgb, sampleRamp } from '../images/raster.js'
import { splitmix32 } from './prng.js'

const TAU = Math.PI * 2

/** Entries in the colour lookup table. Past what 8-bit output can resolve. */
const RAMP_STEPS = 512

/**
 * Hard ceiling on walking, across the whole render.
 *
 * A cluster grown at low stickiness on a large grid can wander for a very long
 * time per particle, and the last particles are the slowest — the spawn circle
 * grows with the cluster, so the empty distance to cross grows too. This stops a
 * pathological parameter set at "fewer branches than you asked for" rather than
 * at "the tab stopped responding".
 */
const MAX_STEPS = 40000000

/**
 * Free space left around the cluster when a particle is released, in cells.
 *
 * Releasing right at the surface would bias where particles arrive towards
 * wherever they happened to be dropped; releasing far away costs walking for
 * nothing. Five cells of clearance is enough for the launch angle to have been
 * forgotten by the time the particle reaches the cluster.
 */
const LAUNCH_GAP = 5

export function render (ctx, spec) {
  const { width, height, cols, rows, particles, palette } = spec
  const random = splitmix32(spec.seed >>> 0)

  // Arrival order, one past zero so that zero can mean empty. This is both the
  // occupancy map the walk tests against and the field the colouring reads.
  const grid = new Int32Array(cols * rows)

  const cx = (cols - 1) / 2
  const cy = (rows - 1) / 2
  const radial = spec.seed_shape !== 'line'

  let placed = 0
  let reach = 1

  if (radial) {
    grid[Math.round(cy) * cols + Math.round(cx)] = ++placed
  } else {
    // A seeded floor rather than a seeded point: the same rule run against a
    // line grows a forest of competing spires instead of a star, and the
    // screening that makes one spire beat its neighbour is easier to see when
    // they are all racing in the same direction.
    for (let x = 0; x < cols; x++) grid[(rows - 1) * cols + x] = ++placed
    reach = 0
  }

  let steps = 0

  /*
   * How far the cluster may grow before the run ends.
   *
   * The launch circle has to fit inside the lattice, so this is half the *short*
   * side. Trying half the long side instead — to reach the corners of a
   * landscape canvas — was a real and instructive failure: most of that circle
   * lies off the board, the misses get clamped onto the top and bottom rows, and
   * the cluster then grows two stringy diagonal arms towards the corners at a
   * fractal dimension of 1.54 instead of 1.71. The launch distribution *is* the
   * physics here.
   *
   * The cluster is fitted to the canvas when it is drawn, so stopping at the
   * inscribed circle costs nothing in composition.
   */
  const maxLaunch = radial ? Math.min(cols, rows) / 2 - 1 : rows - 2

  for (let n = placed; n < particles && steps < MAX_STEPS; n++) {
    /*
     * The run ends when the cluster reaches the edge of the board.
     *
     * Not an optimisation — a correctness fix, and the failure it prevents is
     * spectacular. The launch circle sits a few cells outside the cluster's
     * furthest arm, so once that arm nears the boundary every subsequent
     * particle is released right on top of the arms already there. They stick
     * immediately, to each other, and the cluster grows a hard bright ring at
     * exactly the launch radius — a perfect circle drawn by an algorithm that
     * has no circles in it. Stopping is the honest end of the run; the render
     * reports how many particles actually landed.
     */
    if (reach + LAUNCH_GAP >= maxLaunch) break

    let px = 0
    let py = 0
    let stuck = false

    /** Drop a fresh particle on the launch circle, or on the line above a floor. */
    const launch = () => {
      if (!radial) {
        px = (random() * cols) | 0
        py = Math.max(0, rows - 1 - Math.min(maxLaunch, reach + LAUNCH_GAP))

        return
      }

      const angle = random() * TAU
      const r = Math.min(maxLaunch, reach + LAUNCH_GAP)

      // Inside the board by construction — maxLaunch is the inscribed radius —
      // so the clamp is arithmetic hygiene rather than a fallback.
      px = Math.min(cols - 1, Math.max(0, Math.round(cx + Math.cos(angle) * r)))
      py = Math.min(rows - 1, Math.max(0, Math.round(cy + Math.sin(angle) * r)))
    }

    launch()

    while (!stuck && steps < MAX_STEPS) {
      steps++

      /*
       * How far the particle is from anything it could possibly touch.
       *
       * For a radial cluster that is its distance from the centre minus the
       * cluster's own reach; for a floor it is the vertical gap. Either way it
       * is a *lower bound* on the free space around the particle, which is what
       * makes the long step below exact rather than approximate: nothing can be
       * hit before the particle has travelled that far, whichever direction it
       * chooses.
       */
      const clear = radial
        ? Math.hypot(px - cx, py - cy) - reach - 1
        : rows - 1 - py - reach - 1

      if (clear > 1.5) {
        const angle = random() * TAU
        px = Math.round(px + Math.cos(angle) * clear)
        py = Math.round(py + Math.sin(angle) * clear)
      } else {
        // Close in, one lattice cell at a time, diagonals included. A four-
        // neighbour walk grows a cluster with visible lattice anisotropy — the
        // arms line up with the axes — and the eight-neighbour version does not.
        const d = (random() * 8) | 0
        px += (d === 0 || d === 6 || d === 7) ? -1 : (d >= 1 && d <= 3) ? 1 : 0
        py += (d <= 1 || d === 7) ? -1 : (d >= 3 && d <= 5) ? 1 : 0
      }

      if (px < 0 || px >= cols || py < 0 || py >= rows) {
        /*
         * A particle that has wandered off the board is relaunched rather than
         * abandoned. Abandoning it would make the particle count a request
         * rather than a promise, and would quietly bias the cluster: the
         * particles most likely to escape are the ones released opposite a long
         * arm, so the arms already behind would fall further behind.
         *
         * For a floor cluster the sides wrap instead, because a spire near the
         * edge would otherwise be starved of the arrivals a spire in the middle
         * gets from both sides.
         */
        if (!radial && py >= 0 && py < rows) {
          px = ((px % cols) + cols) % cols
        } else {
          launch()
        }

        continue
      }

      // Unreachable in practice — a long jump is bounded by the free space and
      // a single step only ever follows a failed contact test, which proves
      // every neighbour empty — but a particle sitting inside the cluster would
      // never terminate, so it is guarded rather than assumed.
      if (grid[py * cols + px]) {
        launch()
        continue
      }

      // Eight-neighbour contact test, run at the cell the particle just entered.
      let touching = false

      for (let dy = -1; dy <= 1 && !touching; dy++) {
        const y = py + dy
        if (y < 0 || y >= rows) continue

        for (let dx = -1; dx <= 1; dx++) {
          const x = px + dx
          if (x < 0 || x >= cols || (dx === 0 && dy === 0)) continue

          if (grid[y * cols + x]) {
            touching = true
            break
          }
        }
      }

      if (!touching) continue

      /*
       * Stickiness below 1 lets a particle brush past without freezing.
       *
       * Not a cosmetic control. Perfect stickiness means a particle freezes at
       * the first tip it meets and the cluster is all tip; a low probability
       * lets particles work their way into the fjords before they commit, so the
       * branches thicken and the fractal dimension climbs towards 2. It is the
       * difference between frost and a sponge.
       */
      if (spec.stickiness < 1 && random() > spec.stickiness) continue

      grid[py * cols + px] = ++placed
      stuck = true

      if (radial) {
        const r = Math.hypot(px - cx, py - cy)
        if (r > reach) reach = r
      } else {
        const r = rows - 1 - py
        if (r > reach) reach = r
      }
    }

    // The cluster has reached the edge of the board; nothing further can stick.
    if (!stuck) break
  }

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

  /*
   * Fit the cluster to the canvas.
   *
   * The same move lsystem.js makes, for the same reason: neither an L-system nor
   * a DLA cluster knows how big it will be until it has been grown, and the
   * alternative to measuring it afterwards is a picture whose subject floats in
   * the middle of an empty frame. Here it also decouples the lattice resolution
   * from the output size — a finer lattice buys finer branches at the same
   * physical size, instead of a smaller cluster.
   */
  let minX = cols
  let maxX = -1
  let minY = rows
  let maxY = -1

  for (let y = 0, i = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++, i++) {
      if (!grid[i]) continue
      if (x < minX) minX = x
      if (x > maxX) maxX = x
      if (y < minY) minY = y
      if (y > maxY) maxY = y
    }
  }

  const pad = Math.min(width, height) * 0.035
  const spanX = Math.max(1, maxX - minX + 1)
  const spanY = Math.max(1, maxY - minY + 1)
  const scale = Math.min((width - 2 * pad) / spanX, (height - 2 * pad) / spanY)

  const originX = (width - spanX * scale) / 2 - minX * scale
  const originY = (height - spanY * scale) / 2 - minY * scale

  const radius = scale * 0.62
  const glow = spec.glow

  for (let y = 0, i = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++, i++) {
      const order = grid[i]
      if (!order) continue

      // Colour by arrival order, so the ramp reads as the history of the growth:
      // the seed and the earliest arrivals at one end, the outermost tips at the
      // other. A cluster coloured by position would only restate its own shape.
      const shade = Math.min(RAMP_STEPS - 1, Math.round((order - 1) / Math.max(1, placed - 1) * (RAMP_STEPS - 1))) * 3

      const px = originX + (x + 0.5) * scale
      const py = originY + (y + 0.5) * scale

      if (glow > 0) {
        // A wide, faint, additive halo under every cell. Haloes overlap where
        // the cluster is dense, so the trunk glows and a lone tip does not,
        // which is depth for the price of one extra splat.
        surface.splat(px, py, ramp[shade], ramp[shade + 1], ramp[shade + 2], glow, radius * 3.4, true)
      }

      surface.splat(px, py, ramp[shade], ramp[shade + 1], ramp[shade + 2], 1, radius, false)
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)

  return { placed, steps }
}
