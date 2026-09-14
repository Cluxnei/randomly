/**
 * Render a pattern generator to a PNG without a browser.
 *
 * The renderers only need createImageData/putImageData, so a tiny shim stands in
 * for a canvas and node's own zlib writes the PNG. This exists so a change to a
 * renderer can be looked at, not just asserted about.
 *
 *   node scripts/render-preview.mjs '<payload json>' out.png
 */
import { deflateSync } from 'node:zlib'
import { writeFileSync } from 'node:fs'
import { Rng } from '../resources/js/rng.js'
import { render as automaton } from '../resources/js/patterns/automaton.js'
import { render as dla } from '../resources/js/patterns/dla.js'
import { render as life } from '../resources/js/patterns/life.js'
import { render as lsystem } from '../resources/js/patterns/lsystem.js'
import { render as maze } from '../resources/js/patterns/maze.js'
import { render as perlin } from '../resources/js/patterns/perlin.js'
import { render as poisson } from '../resources/js/patterns/poisson.js'
import { render as reaction } from '../resources/js/patterns/reaction.js'
import { render as simplex } from '../resources/js/patterns/simplex.js'
import { render as spectral } from '../resources/js/patterns/spectral.js'
import { render as truchet } from '../resources/js/patterns/truchet.js'
import { render as voronoi } from '../resources/js/patterns/voronoi.js'
import { render as walk } from '../resources/js/patterns/walk.js'
import { render as wfc } from '../resources/js/patterns/wfc.js'
import { render as worley } from '../resources/js/patterns/worley.js'
import { render as blob } from '../resources/js/images/blob.js'
import { render as circles } from '../resources/js/images/circles.js'
import { render as flowfield } from '../resources/js/images/flowfield.js'
import { render as gradient } from '../resources/js/images/gradient.js'
import { render as identicon } from '../resources/js/images/identicon.js'
import { render as mondrian } from '../resources/js/images/mondrian.js'
import { render as spray } from '../resources/js/images/spray.js'
import { render as strata } from '../resources/js/images/strata.js'
import { render as tiles } from '../resources/js/images/tiles.js'

const RENDERERS = {
  perlin, simplex, worley, spectral, automaton, life, reaction, truchet, poisson, maze, voronoi, lsystem, wfc, walk, dla,
  flowfield, blob, identicon, circles, mondrian, gradient, spray, tiles, strata,
}

/*
 * The image renderers draw a good deal more per pixel than the pattern ones, so
 * they read more of the stream. Sizing this the way resources/js/renderers.js
 * does would mean importing it, and importing it would pull in canvas.js, which
 * touches `document`. HKDF output is a prefix, so over-deriving here costs a few
 * milliseconds and changes no byte any renderer actually reads.
 */
const STREAM_BYTES = 131072

function shimContext (width, height) {
  let stored = null
  return {
    createImageData: (w, h) => ({ width: w, height: h, data: new Uint8ClampedArray(w * h * 4) }),
    putImageData: (image) => { stored = image },
    get image () { return stored },
  }
}

function crc32 (buf) {
  let c, crc = 0xffffffff
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    crc = (crc >>> 8) ^ c
  }
  return (crc ^ 0xffffffff) >>> 0
}

function chunk (type, data) {
  const length = Buffer.alloc(4)
  length.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body))
  return Buffer.concat([length, body, crc])
}

function encodePng (width, height, rgba) {
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(width, 0)
  ihdr.writeUInt32BE(height, 4)
  ihdr[8] = 8   // bit depth
  ihdr[9] = 6   // colour type: RGBA

  // Each scanline is prefixed with its filter byte; 0 means "none", which costs
  // compression ratio and costs nothing else for a preview.
  const raw = Buffer.alloc(height * (width * 4 + 1))
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0
    Buffer.from(rgba.buffer, y * width * 4, width * 4).copy(raw, y * (width * 4 + 1) + 1)
  }

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw, { level: 6 })),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

import { readFileSync } from 'node:fs'

// Accept either inline JSON (handy from a shell) or a path to a file. The API
// takes the file route: a spec with a long palette or a big score can exceed the
// argument-length limit, and failing there would be a confusing way to find out.
const argument = process.argv[2]
const payload = JSON.parse(
  argument.trim().startsWith('{') ? argument : readFileSync(argument, 'utf8'),
)
const out = process.argv[3]
const spec = payload.value

const rng = await Rng.from(payload.render_key, 'randomly/render', STREAM_BYTES)
const ctx = shimContext(spec.width, spec.height)

const started = performance.now()
RENDERERS[spec.algorithm](ctx, spec, rng)
const ms = Math.round(performance.now() - started)

writeFileSync(out, encodePng(spec.width, spec.height, ctx.image.data))
console.log(JSON.stringify({ out, ms, pixels: spec.width * spec.height }))
