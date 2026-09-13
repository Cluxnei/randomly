/**
 * Elementary cellular automata.
 *
 * A row of bits, a lookup table of eight entries, and each row derived from the
 * one above it:
 *
 *   next[i] = table[ (left << 2) | (centre << 1) | right ]
 *
 * That is the entire algorithm. Everything on screen — the Sierpiński triangle of
 * rule 90, the chaos of rule 30, the gliders of rule 110 — comes out of those
 * three bits and the table the server resolved from the rule number.
 *
 * Deliberately two flat colours. A gradient would be a lie here: the values are
 * 0 and 1, not a field, and shading them would suggest an in-between state that
 * does not exist.
 */
import { hash2, hexToRgb } from './shared.js'

export function render (ctx, spec, rng) {
  const { width, height, table, cell, wrap, density, palette } = spec

  const seed = spec.seed >>> 0
  const cols = Math.max(1, Math.ceil(width / cell))
  const rows = Math.max(1, Math.ceil(height / cell))

  const [dead, alive] = palette.map(hexToRgb)

  let state = new Uint8Array(cols)
  let next = new Uint8Array(cols)

  if (spec.start === 'random') {
    // Hashed rather than drawn from the stream: a 2048-pixel canvas at one pixel
    // per cell wants two thousand decisions, and the render stream is 8 KB.
    for (let i = 0; i < cols; i++) {
      state[i] = hash2(seed, i, 0) / 4294967296 < density ? 1 : 0
    }
  } else {
    state[cols >> 1] = 1
  }

  const image = ctx.createImageData(width, height)
  const data = image.data

  for (let row = 0; row < rows; row++) {
    /*
     * One row of cells is expanded to pixels once and then memcpy'd down for the
     * remaining cell-height scanlines. At cell=1 this is a no-op; at cell=16 it
     * turns sixteen passes of per-pixel colour selection into one pass and
     * fifteen typed-array copies, which the engine does at memory speed.
     */
    const top = row * cell
    if (top >= height) break

    let p = top * width * 4
    for (let i = 0; i < cols; i++) {
      const c = state[i] ? alive : dead

      for (let dx = 0; dx < cell; dx++) {
        if (i * cell + dx >= width) break
        data[p++] = c[0]
        data[p++] = c[1]
        data[p++] = c[2]
        data[p++] = 255
      }
    }

    const stride = width * 4
    const scanline = data.subarray(top * stride, top * stride + stride)
    for (let dy = 1; dy < cell && top + dy < height; dy++) {
      data.set(scanline, (top + dy) * stride)
    }

    for (let i = 0; i < cols; i++) {
      // Off the edge the world is either a torus or a wall of dead cells.
      // Reflecting instead would introduce a symmetry the rule never asked for.
      const left = i === 0 ? (wrap ? state[cols - 1] : 0) : state[i - 1]
      const right = i === cols - 1 ? (wrap ? state[0] : 0) : state[i + 1]

      next[i] = table[(left << 2) | (state[i] << 1) | right]
    }

    const swap = state
    state = next
    next = swap
  }

  ctx.putImageData(image, 0, 0)
}
