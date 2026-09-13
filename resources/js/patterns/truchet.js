/**
 * Truchet tiles.
 *
 * One square motif per cell, rotated at random, and the eye does the rest: arcs
 * meet across tile edges whatever the rotations happen to be, so the grid
 * dissolves into loops and meanders that nothing in the code drew.
 *
 * Drawn as a signed distance per pixel rather than with canvas paths. The motifs
 * are all distances anyway — a quarter arc is ‖p − c‖ = ½, a diagonal is a line,
 * a triangle is a half-plane — so this is the shorter route, and it comes with
 * free antialiasing: a path-stroked arc at 72 tiles across is a staircase, and
 * this is not.
 */
import { hash2, hexToRgb } from './shared.js'

const SQRT1_2 = Math.SQRT1_2

/**
 * Signed distance to the motif, in tile units. Negative is inside the ink.
 *
 * `u`, `v` are the position within the tile, both in [0, 1).
 */
function motif (set, orient, u, v, half) {
  switch (set) {
    case 1: { // diagonals: the 1704 original, a line between opposite corners
      const d = orient & 1 ? Math.abs(u + v - 1) : Math.abs(u - v)
      return d * SQRT1_2 - half
    }

    case 2: { // triangles: a half-plane, so the tile is half ink and half ground
      switch (orient & 3) {
        case 0: return (u + v - 1) * SQRT1_2
        case 1: return (v - u) * SQRT1_2
        case 2: return (1 - u - v) * SQRT1_2
        default: return (u - v) * SQRT1_2
      }
    }

    default: { // quarter arcs: two circles of radius ½ on opposite corners
      const ax = orient & 1 ? 1 : 0
      const dx1 = u - ax
      const dy1 = v
      const dx2 = u - (1 - ax)
      const dy2 = v - 1

      const d1 = Math.abs(Math.sqrt(dx1 * dx1 + dy1 * dy1) - 0.5)
      const d2 = Math.abs(Math.sqrt(dx2 * dx2 + dy2 * dy2) - 0.5)

      return (d1 < d2 ? d1 : d2) - half
    }
  }
}

export function render (ctx, spec, rng) {
  const { width, height, palette, colouring } = spec

  const seed = spec.seed >>> 0
  const colours = palette.map(hexToRgb)
  const last = colours.length - 1

  const size = Math.max(width, height) / Math.max(1, spec.tiles)
  const cols = Math.ceil(width / size)
  const rows = Math.ceil(height / size)

  const named = { arcs: 0, diagonals: 1, triangles: 2, mixed: 3 }
  const chosen = named[spec.set] ?? 0

  /*
   * Orientations are decided once per tile, not once per pixel.
   *
   * The hash is cheap, but it is not free, and at 900×600 the per-pixel version
   * would run it half a million times to answer three thousand questions. Baking
   * the grid first also means a tile's whole area sees one answer, which matters:
   * a hash called per pixel with a rounding difference at a tile edge would tear
   * the motif in half.
   */
  const sets = new Uint8Array(cols * rows)
  const orients = new Uint8Array(cols * rows)

  for (let ty = 0; ty < rows; ty++) {
    for (let tx = 0; tx < cols; tx++) {
      const h = hash2(seed, tx, ty)
      const i = ty * cols + tx

      sets[i] = chosen === 3 ? (h >>> 28) % 3 : chosen
      orients[i] = (h >>> 24) & 3
    }
  }

  /*
   * The ground is the darkest stop and the ink comes from the rest of the ramp,
   * so ink and ground can never collide however the palette was drawn. That is
   * the whole reason the first colour is reserved rather than used.
   */
  const ground = colours[0]

  // The antialiasing width: one pixel, expressed in tile units, which is what
  // the distance above is measured in.
  const pixel = 1 / size

  const image = ctx.createImageData(width, height)
  const data = image.data

  let p = 0
  for (let y = 0; y < height; y++) {
    const gy = y / size
    const ty = Math.floor(gy)
    const v = gy - ty

    for (let x = 0; x < width; x++) {
      const gx = x / size
      const tx = Math.floor(gx)
      const u = gx - tx

      const i = ty * cols + tx
      const d = motif(sets[i], orients[i], u, v, spec.weight * 0.5)

      // Linear ramp across one pixel of distance, centred on the boundary. A hard
      // threshold here is the difference between a printed pattern and a jagged
      // one, and it costs a subtraction.
      let cover = 0.5 - d / pixel
      cover = cover < 0 ? 0 : cover > 1 ? 1 : cover

      if (cover === 0) {
        data[p++] = ground[0]
        data[p++] = ground[1]
        data[p++] = ground[2]
        data[p++] = 255
        continue
      }

      let t
      switch (colouring) {
        // A diagonal gradient rather than a horizontal one: it cuts across the
        // tile grid instead of running along it, so the colour never lines up
        // with a tile edge and give the underlying lattice away.
        case 'diagonal': t = (tx + ty) / Math.max(1, cols + rows - 2); break
        case 'tile': t = hash2(seed ^ 0x9e3779b9, tx, ty) / 4294967296; break
        default: t = 1
      }

      // Index 0 is the ground, so the ink walks 1..last.
      const position = 1 + t * (last - 1)
      const lower = Math.floor(position)
      const upper = Math.min(last, lower + 1)
      const k = position - lower
      const a = colours[lower]
      const b = colours[upper]

      const r = a[0] + (b[0] - a[0]) * k
      const g = a[1] + (b[1] - a[1]) * k
      const bl = a[2] + (b[2] - a[2]) * k

      data[p++] = ground[0] + (r - ground[0]) * cover
      data[p++] = ground[1] + (g - ground[1]) * cover
      data[p++] = ground[2] + (bl - ground[2]) * cover
      data[p++] = 255
    }
  }

  ctx.putImageData(image, 0, 0)
}
