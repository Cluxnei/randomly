/**
 * Circle packing by dart throwing.
 *
 * Pick a point, grow a circle there until it touches something, keep it if it
 * ended up big enough to be worth drawing. Repeat a few tens of thousands of
 * times. That is the whole algorithm, and the reason it works is that the
 * circles are placed in no order at all: the early ones are large because the
 * canvas is empty, and every later one has to fit whatever gap is left, so the
 * size distribution and the apparent structure are both consequences of nothing
 * more than the order of the draws.
 *
 * The acceleration is the only fiddly part. Testing a candidate against every
 * circle already placed would be quadratic and a full-size pack is tens of
 * thousands of circles, so they go into a grid and the candidate walks rings of
 * grid cells outwards, stopping as soon as the next ring cannot possibly hold
 * anything nearer than the closest circle already found — the same exact-search
 * shape patterns/voronoi.js uses, for the same reason.
 */
import { fractal } from '../patterns/perlin.js'
import { splitmix32 } from '../patterns/prng.js'
import { Surface, hexToRgb, sampleRamp } from './raster.js'
import { ring } from './shapes.js'

/** Entries in the colour lookup table. Past what 8-bit output can resolve. */
const RAMP_STEPS = 512

/** A permutation table, a few thousand rejections' worth of slack, and no more. */
export function bytesNeeded () {
  return 4096
}

/**
 * The pack itself.
 *
 * Exported so tests/Unit/ImageGeneratorsTest.php can check the one promise this
 * generator makes — that no two circles overlap — against the real packer rather
 * than against a second one written to agree with it.
 *
 * @returns {Float32Array} x, y, radius, three at a time
 */
export function pack (random, spec) {
  const { width, height, attempts, gap } = spec
  const maxRadius = spec.max_radius
  const minRadius = spec.min_radius

  // One circle per grid cell at most, roughly: a cell the size of the largest
  // circle keeps the ring walk short without making any single cell's list long.
  const cell = Math.max(4, maxRadius + gap)
  const cols = Math.max(1, Math.ceil(width / cell))
  const rows = Math.max(1, Math.ceil(height / cell))
  const buckets = Array.from({ length: cols * rows }, () => [])

  const out = []

  for (let attempt = 0; attempt < attempts; attempt++) {
    const x = random() * width
    const y = random() * height

    const bx = Math.min(cols - 1, (x / cell) | 0)
    const by = Math.min(rows - 1, (y / cell) | 0)

    /*
     * How large this circle may grow.
     *
     * Deliberately *not* bounded by the canvas edge. A pack whose circles all
     * stop short of the frame draws its own rectangle in negative space and
     * reads as a diagram of itself; letting them run off the edge is what makes
     * the composition look like a crop of something larger, which is the whole
     * difference between this and a screensaver.
     */
    let best = maxRadius

    for (let r = 0; r < cols + rows; r++) {
      /*
       * When to stop walking outwards.
       *
       * A circle is bucketed by its *centre*, so a circle whose surface is
       * within `best` of the candidate can have its centre a further
       * maxRadius + gap away — which is one whole cell, by construction. Ring r
       * holds centres at least (r−1) cells out, so the search may stop once
       * (r−2) cells exceeds the best radius found, and stopping one ring earlier
       * than that silently misses overlaps at the largest sizes.
       */
      if (r > 1 && (r - 2) * cell > best) break

      for (let gy = by - r; gy <= by + r; gy++) {
        if (gy < 0 || gy >= rows) continue

        for (let gx = bx - r; gx <= bx + r; gx++) {
          if (gx < 0 || gx >= cols) continue
          // Only the perimeter is new; the interior was covered by earlier rings.
          if (r > 0 && Math.abs(gx - bx) !== r && Math.abs(gy - by) !== r) continue

          for (const i of buckets[gy * cols + gx]) {
            const d = Math.hypot(out[i] - x, out[i + 1] - y) - out[i + 2] - gap

            if (d < best) best = d
          }
        }
      }
    }

    if (best < minRadius) continue

    const index = out.length
    out.push(x, y, best)
    buckets[by * cols + bx].push(index)
  }

  return Float32Array.from(out)
}

export function render (ctx, spec, rng) {
  const { width, height, palette } = spec
  const random = splitmix32(spec.seed >>> 0)
  const circles = pack(random, spec)

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

  // Used only by the field colouring, but the permutation has to be drawn
  // unconditionally: the render key's stream is read in order, and a table drawn
  // only sometimes would make the same seed produce two different pictures
  // depending on a colour menu.
  const perm = rng.permutation()
  const fieldStep = 2.4 / Math.max(width, height)
  const noise = { octaves: 3, persistence: 0.5, lacunarity: 2.0, variant: 'fbm' }

  const count = circles.length / 3

  /*
   * Three ways to choose a colour, and they are not interchangeable.
   *
   * By radius is the informative one: the palette then *is* the size
   * distribution, and the big early circles read as a different population from
   * the crumbs that filled in behind them. By field is the handsome one —
   * neighbouring circles land on neighbouring colours, so the pack reads as one
   * composition rather than as confetti. By order is the honest one: it shows
   * that the whole structure is a consequence of arrival time.
   */
  const t = new Float32Array(count)

  for (let i = 0, n = 0; i < circles.length; i += 3, n++) {
    if (spec.colouring === 'field') {
      t[n] = fractal(perm, circles[i] * fieldStep, circles[i + 1] * fieldStep, noise)
    } else if (spec.colouring === 'order') {
      t[n] = n
    } else {
      t[n] = circles[i + 2]
    }
  }

  /*
   * Two-pass normalise, the same rule docs/07 §9 states for fields.
   *
   * No fixed remap fits: a three-octave fBm almost never leaves the middle
   * third of [−1, 1], and the largest circle a pack actually achieves is well
   * under the slider's maximum whenever the darts are dense. Mapping either one
   * directly left the first render using about a fifth of the palette — several
   * hundred circles in four shades of the same blue.
   *
   * The min and max are read back out of the Float32Array before being
   * compared, for the reason that section gives: storing rounds to float32.
   */
  let low = Infinity
  let high = -Infinity

  for (let n = 0; n < count; n++) {
    const v = t[n]
    if (v < low) low = v
    if (v > high) high = v
  }

  const span = high - low || 1

  for (let i = 0, n = 0; i < circles.length; i += 3, n++) {
    const x = circles[i]
    const y = circles[i + 1]
    const radius = circles[i + 2]

    const shade = Math.min(RAMP_STEPS - 1, Math.max(0,
      Math.round((t[n] - low) / span * (RAMP_STEPS - 1))
    )) * 3

    const r = ramp[shade]
    const g = ramp[shade + 1]
    const b = ramp[shade + 2]

    const style = spec.style === 'mixed'
      ? (random() < 0.45 ? 'rings' : 'filled')
      : spec.style

    if (style === 'filled') {
      surface.splat(x, y, r, g, b, 1, radius, false)
      continue
    }

    // Stroke width scales with the circle rather than being fixed: a constant
    // weight makes the small circles solid blobs and the large ones hairlines,
    // which loses the size distribution the pack is about.
    const weight = Math.max(0.9, radius * spec.weight)

    if (style === 'rings') {
      ring(surface, x, y, Math.max(weight, radius - weight / 2), weight, r, g, b, 1)
      continue
    }

    /*
     * Nested: concentric rings in towards the middle, as many as fit.
     *
     * The count scales with the radius, so a large circle is genuinely more
     * detailed rather than the same drawing enlarged — which is what a fixed
     * count gives, and it makes every circle look like a copy of every other.
     */
    const gapBetween = weight * 2.1
    let current = radius - weight / 2

    while (current > weight) {
      ring(surface, x, y, current, weight, r, g, b, 1)
      current -= gapBetween
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)

  return { circles: count }
}
