/**
 * Pack circles and measure the closest approach between any two of them.
 *
 * `images.circles` promises exactly one thing: no circle overlaps another, and
 * every pair is at least the requested gap apart. The packer lives in the browser
 * — a full-size pack is tens of thousands of circles and far too many to ship —
 * so the promise is checked by running the real packer and reporting what it
 * produced, and tests/Unit/ImageCompositionsTest.php asserts against that.
 *
 * The measurement is brute force over every pair, deliberately. Reusing the
 * bucket grid the packer searches with would make the test agree with the bug it
 * is looking for: a packer that queries the wrong buckets and a checker that
 * queries the same wrong buckets would both miss the same overlap.
 *
 *   node scripts/check-packing.mjs <width> <height> <attempts> <min> <max> <gap> <seed>
 */
import { pack } from '../resources/js/images/circles.js'
import { splitmix32 } from '../resources/js/patterns/prng.js'

const [width, height, attempts, min, max, gap, seed] = process.argv.slice(2).map(Number)

const circles = pack(splitmix32(seed >>> 0), {
  width,
  height,
  attempts,
  min_radius: min,
  max_radius: max,
  gap,
})

const count = circles.length / 3
let closest = Infinity
let largest = 0
let smallest = Infinity

for (let i = 0; i < count; i++) {
  const r = circles[i * 3 + 2]
  if (r > largest) largest = r
  if (r < smallest) smallest = r

  for (let j = i + 1; j < count; j++) {
    // Surface to surface, so a negative number is an overlap and zero is a
    // tangent. The packer's own gap should keep every one of these at or above
    // `gap`, within floating-point noise.
    const d = Math.hypot(circles[i * 3] - circles[j * 3], circles[i * 3 + 1] - circles[j * 3 + 1])
      - r - circles[j * 3 + 2]

    if (d < closest) closest = d
  }
}

console.log(JSON.stringify({
  circles: count,
  closest_surfaces: count < 2 ? null : closest,
  smallest_radius: count ? smallest : null,
  largest_radius: count ? largest : null,
}))
