/**
 * Measure the closest pair in each of the two point sets a blue-noise render draws.
 *
 * The separation guarantee is the entire claim `patterns.poisson` makes, and it
 * lives in JavaScript — the sampler runs in the browser because a dense field is
 * tens of thousands of points and far too many to ship. A guarantee nothing checks
 * is a guarantee that quietly stops holding, so the check runs the real sampler
 * from the real renderer and reports what it found, and
 * tests/Unit/StructuredPatternsTest.php asserts against it.
 *
 *   node scripts/check-blue-noise.mjs <width> <height> <radius> <candidates> <seed>
 */
import { bridson, scatter } from '../resources/js/patterns/poisson.js'
import { splitmix32 } from '../resources/js/patterns/prng.js'

const [width, height, radius, candidates, seed] = process.argv.slice(2).map(Number)

/** Brute force, deliberately: the test must not share the grid the sampler uses to decide. */
function closestPair (points) {
  const count = points.length / 2
  let best = Infinity

  for (let i = 0; i < count; i++) {
    for (let j = i + 1; j < count; j++) {
      const dx = points[i * 2] - points[j * 2]
      const dy = points[i * 2 + 1] - points[j * 2 + 1]
      const d = dx * dx + dy * dy
      if (d < best) best = d
    }
  }

  return count < 2 ? 0 : Math.sqrt(best)
}

const random = splitmix32(seed >>> 0)
const blue = bridson(random, width, height, radius, candidates)
const uniform = scatter(random, width, height, blue.length / 2)

console.log(JSON.stringify({
  points: blue.length / 2,
  closest_blue: closestPair(blue),
  closest_uniform: closestPair(uniform),
}))
