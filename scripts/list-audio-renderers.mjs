/**
 * Print every algorithm the browser can actually synthesise.
 *
 * The audio twin of list-renderers.mjs, and it exists for the same reason: an
 * audio generator ships a score instead of samples, so the synthesis lives in
 * JavaScript and nothing on the PHP side can tell whether it exists. Ship one
 * without its engine and the studio serves a silent player — green suite, 200
 * response, and only somebody pressing play finds out.
 *
 * Asked of the engine itself rather than parsed out of it, because a regex over
 * a registry is a check that quietly stops working the first time somebody
 * reformats the map. Used by tests/Unit/AudioGeneratorsTest.php.
 */
import { isRenderable } from '../resources/js/audio/engine.js'

const algorithms = process.argv.slice(2)

console.log(JSON.stringify(
  Object.fromEntries(algorithms.map(a => [a, isRenderable(a)])),
))
