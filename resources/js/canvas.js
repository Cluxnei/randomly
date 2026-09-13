/**
 * The canvas harness: payload in, pixels out, PNG on request.
 *
 * Every visual generator goes through here, so the seed handling, the device
 * pixel ratio and the export path are written once. A renderer only has to know
 * how to fill an ImageData.
 */
import { Rng } from './rng.js'
import { render as automaton } from './patterns/automaton.js'
import { render as perlin } from './patterns/perlin.js'
import { render as reaction } from './patterns/reaction.js'
import { render as truchet } from './patterns/truchet.js'
import { render as worley } from './patterns/worley.js'

const RENDERERS = {
  perlin,
  worley,
  automaton,
  reaction,
  truchet,
}

export function isRenderable (algorithm) {
  return algorithm in RENDERERS
}

/**
 * Draw a generation onto a canvas.
 *
 * `payload` is the API response: `render_key` plus the `value` spec the generator
 * emitted. Both halves matter — the key fixes the randomness, the spec fixes the
 * shape — which is exactly the distinction the studio's two buttons teach.
 */
export async function draw (canvas, payload) {
  const spec = payload.value
  const renderer = RENDERERS[spec.algorithm]

  if (!renderer) {
    throw new Error(`No client renderer for [${spec.algorithm}].`)
  }

  // A permutation table needs 256 shuffled bytes plus its rejections; 8 KB is
  // generous and still one HMAC block away from nothing.
  const rng = await Rng.from(payload.render_key, 'randomly/render', 8192)

  canvas.width = spec.width
  canvas.height = spec.height

  const ctx = canvas.getContext('2d', { alpha: false })
  const started = performance.now()

  renderer(ctx, spec, rng)

  return {
    milliseconds: Math.round(performance.now() - started),
    pixels: spec.width * spec.height,
  }
}

/**
 * Hand the viewer a PNG.
 *
 * The receipt line is burned into the corner before export, so a shared image
 * carries its own provenance — every share is an advertisement that explains
 * the product.
 */
export async function toPng (canvas, { filename = 'randomly.png', caption = null } = {}) {
  let source = canvas

  if (caption) {
    source = document.createElement('canvas')
    source.width = canvas.width
    source.height = canvas.height

    const ctx = source.getContext('2d')
    ctx.drawImage(canvas, 0, 0)

    const pad = Math.round(canvas.width * 0.018)
    const size = Math.max(11, Math.round(canvas.width * 0.013))

    ctx.font = `${size}px "JetBrains Mono", ui-monospace, monospace`
    ctx.textBaseline = 'bottom'

    // Drawn twice: a dark shadow under light text, so the caption stays readable
    // whatever the generated colours underneath happen to be.
    ctx.fillStyle = 'rgba(0,0,0,0.55)'
    ctx.fillText(caption, pad + 1, canvas.height - pad + 1)
    ctx.fillStyle = 'rgba(255,255,255,0.92)'
    ctx.fillText(caption, pad, canvas.height - pad)
  }

  const blob = await new Promise(resolve => source.toBlob(resolve, 'image/png'))
  const url = URL.createObjectURL(blob)

  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()

  URL.revokeObjectURL(url)

  return blob.size
}
