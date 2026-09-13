/**
 * Print every algorithm the browser can actually draw.
 *
 * Asked of the modules themselves rather than parsed out of them: the two
 * registries use different value shapes, and a regex over source is exactly the
 * kind of check that quietly stops working the first time someone reformats a
 * map. Used by tests/Unit/RendererCoverageTest.php.
 */
import { isRenderable } from '../resources/js/renderers.js'

const algorithms = process.argv.slice(2)

console.log(JSON.stringify(
  Object.fromEntries(algorithms.map(a => [a, isRenderable(a)])),
))
