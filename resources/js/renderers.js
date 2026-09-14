/**
 * One entry point for every client-side renderer.
 *
 * The Patterns module owns `canvas.js` and its RENDERERS map. Rather than both
 * modules editing one table — which is a merge conflict waiting to happen and,
 * worse, a single list that has to be kept in sync with two directories — the
 * image renderers are registered here and `canvas.js` is the fallback. The studio
 * calls this file and never needs to know which module a generator came from; it
 * only knows the generator declared `renderer: canvas`.
 *
 * The other reason for the indirection is the stream budget. `canvas.js` derives
 * a fixed 8 KB, which is generous for a permutation table and nothing like enough
 * for a flow field that draws four numbers per particle. Each renderer here
 * declares what it needs and gets exactly that. HKDF output is a prefix — the
 * first 8 KB of a 60 KB expansion are the same 8 KB — so asking for more never
 * changes a single byte either module would have read.
 */
import { draw as drawPattern, isRenderable as isPattern } from './canvas.js'
import { Rng } from './rng.js'
import { bytesNeeded as blobBytes, render as blob } from './images/blob.js'
import { bytesNeeded as circlesBytes, render as circles } from './images/circles.js'
import { bytesNeeded as flowfieldBytes, createPass, render as flowfield } from './images/flowfield.js'
import { bytesNeeded as gradientBytes, render as gradient } from './images/gradient.js'
import { bytesNeeded as identiconBytes, render as identicon } from './images/identicon.js'
import { bytesNeeded as mondrianBytes, render as mondrian } from './images/mondrian.js'
import { bytesNeeded as sprayBytes, render as spray } from './images/spray.js'
import { bytesNeeded as strataBytes, render as strata } from './images/strata.js'
import { bytesNeeded as tilesBytes, render as tiles } from './images/tiles.js'

const IMAGE_RENDERERS = {
  flowfield: { render: flowfield, bytes: flowfieldBytes },
  blob: { render: blob, bytes: blobBytes },
  identicon: { render: identicon, bytes: identiconBytes },
  circles: { render: circles, bytes: circlesBytes },
  mondrian: { render: mondrian, bytes: mondrianBytes },
  gradient: { render: gradient, bytes: gradientBytes },
  spray: { render: spray, bytes: sprayBytes },
  tiles: { render: tiles, bytes: tilesBytes },
  strata: { render: strata, bytes: strataBytes },
}

export function isRenderable (algorithm) {
  return algorithm in IMAGE_RENDERERS || isPattern(algorithm)
}

/** Derive the stream a spec's renderer will read, sized to that renderer. */
async function streamFor (entry, payload) {
  return Rng.from(payload.render_key, 'randomly/render', entry.bytes(payload.value))
}

function prepare (canvas, spec) {
  // The backing store is the spec's pixel size; CSS scales it to fit. Setting
  // width/height also clears the canvas, which is what makes a redraw a redraw
  // rather than a second drawing on top of the first.
  canvas.width = spec.width
  canvas.height = spec.height

  return canvas.getContext('2d', { alpha: false })
}

/**
 * Draw a generation onto a canvas, whichever module it came from.
 *
 * Returns the same `{ milliseconds, pixels }` shape `canvas.js` returns, because
 * the studio reports it in the meta strip next to the server's own duration and
 * the contrast between the two is worth showing honestly.
 */
export async function render (canvas, payload) {
  const entry = IMAGE_RENDERERS[payload.value.algorithm]

  if (!entry) {
    return drawPattern(canvas, payload)
  }

  const rng = await streamFor(entry, payload)
  const ctx = prepare(canvas, payload.value)
  const started = performance.now()

  entry.render(ctx, payload.value, rng)

  return {
    milliseconds: Math.round(performance.now() - started),
    pixels: payload.value.width * payload.value.height,
  }
}

/**
 * A flow field that can be grown across frames instead of drawn in one blocking
 * call. Used by the landing hero, which has a whole page to paint around it.
 */
export async function flowFieldPass (canvas, payload) {
  const rng = await streamFor(IMAGE_RENDERERS.flowfield, payload)

  return createPass(prepare(canvas, payload.value), payload.value, rng)
}
