/**
 * Draw a subdivision the server has already made.
 *
 * The whole of this generator's interest is in where the cuts fell, and that
 * happens in PHP — see MondrianGenerator, which explains why. What is left here
 * is the part that has to be pixels: fill the ground, then fill every cell inset
 * by half the gutter.
 *
 * **Nothing draws the black lines.** They are the ground, showing through the gap
 * left around each rectangle. Stroking the cell borders instead would double the
 * ink on every shared edge — two neighbours both stroking the line between them —
 * and the internal lines would come out twice the weight of the outer ones.
 */
import { Surface, hexToRgb } from './raster.js'
import { rect } from './shapes.js'

/** Nothing is drawn from the stream; the cells arrive fully specified. */
export function bytesNeeded () {
  return 1024
}

export function render (ctx, spec) {
  const surface = new Surface(spec.width, spec.height)

  const [br, bg, bb] = hexToRgb(spec.background)
  surface.fill(br, bg, bb)

  const paper = hexToRgb(spec.paper)
  const palette = spec.palette.map(hexToRgb)

  // Half the gutter on each side of a shared edge makes a gutter of the full
  // width between two cells — and half of one along the canvas edge, which is
  // the frame Mondrian actually painted.
  const inset = spec.stroke / 2

  for (const [x, y, w, h, index] of spec.cells) {
    const colour = index < 0 ? paper : palette[Math.min(palette.length - 1, index)]

    const x0 = x + inset
    const y0 = y + inset
    const x1 = x + w - inset
    const y1 = y + h - inset

    // A gutter wider than the cell would invert the rectangle and fill the whole
    // canvas, because the loop bounds would run backwards. A cell that has been
    // eaten by its own border is simply not drawn.
    if (x1 <= x0 || y1 <= y0) continue

    rect(surface, x0, y0, x1, y1, colour[0], colour[1], colour[2], 1)
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
