/**
 * A generative tile grid: one glyph and one rotation per cell.
 *
 * The oldest trick in generative art and still one of the best, because the
 * interest is not in any tile but in what happens at the joins. Neighbouring
 * cells whose glyphs happen to meet read as one continuous figure, and the eye
 * assembles those into shapes nobody designed — the same effect that makes a
 * Truchet tiling look like a maze rather than like a hundred quarter-circles.
 *
 * Everything is derived by hashing the cell's own coordinates, exactly as
 * truchet.js does. A grid can be two thousand cells and the render stream is
 * 8 KB, so drawing a glyph, a rotation and a colour per cell from the stream is
 * not an option — and hashing is better anyway, because a cell's appearance is
 * then a function of where it is rather than of when it was drawn.
 *
 * The glyph set is deliberately small. Twenty glyphs would make every cell a
 * surprise and the grid would read as noise; a dozen, with weights, gives the
 * repetition the eye needs before it can notice a coincidence.
 */
import { hash2 } from '../patterns/shared.js'
import { Surface, hexToRgb } from './raster.js'
import { arc, disc, rect, ring, segment, triangle } from './shapes.js'

/** Nothing is read from the stream; everything is hashed from the spec's seed. */
export function bytesNeeded () {
  return 1024
}

/**
 * The glyph families, in the order the `set` parameter indexes them.
 *
 * Grouped rather than offered individually because the groups are what have a
 * look: curves alone make something that reads as plumbing, lines alone as a
 * circuit diagram, solids alone as a quilt. Mixing all three is the busiest and
 * usually the best.
 */
const SETS = {
  curves: ['arcs', 'quarter', 'chevron', 'ring', 'dot'],
  lines: ['diagonal', 'cross', 'bar', 'tee', 'corner'],
  solids: ['triangle', 'half', 'quarterDisc', 'dot', 'eye'],
  all: ['arcs', 'quarter', 'chevron', 'ring', 'dot', 'diagonal', 'cross', 'bar', 'tee', 'corner', 'triangle', 'half', 'quarterDisc', 'eye'],
}

/**
 * Draw one glyph into a cell.
 *
 * Rotation is applied to unit coordinates rather than to the finished drawing:
 * `u()` and `v()` map a point in the glyph's own [0,1]² space through r quarter
 * turns and out to pixels. Every glyph is then written once, in its own frame,
 * and the four orientations come for free.
 *
 * Quadrant indices rotate too — a quarter arc in the top-left corner becomes one
 * in the top-right — which is the +r on every `arc` call below.
 */
function glyph (surface, name, r, x0, y0, size, cr, cg, cb, weight) {
  // (u, v) → pixels, through r clockwise quarter turns.
  const u = (a, b) => x0 + size * (r === 0 ? a : r === 1 ? 1 - b : r === 2 ? 1 - a : b)
  const v = (a, b) => y0 + size * (r === 0 ? b : r === 1 ? a : r === 2 ? 1 - b : 1 - a)

  const w = size * weight
  const line = (ax, ay, bx, by) => segment(surface, u(ax, ay), v(ax, ay), u(bx, by), v(bx, by), w, cr, cg, cb)

  switch (name) {
    case 'arcs':
      // The Truchet pair: two quarter arcs meeting the cell edges at their
      // midpoints, so they join whatever the neighbour does.
      arc(surface, u(0, 0), v(0, 0), size * 0.5, w, (0 + r) % 4, cr, cg, cb)
      arc(surface, u(1, 1), v(1, 1), size * 0.5, w, (2 + r) % 4, cr, cg, cb)
      break

    case 'quarter':
      arc(surface, u(0, 0), v(0, 0), size * 0.5, w, (0 + r) % 4, cr, cg, cb)
      break

    case 'chevron':
      arc(surface, u(0, 0), v(0, 0), size * 0.3, w, (0 + r) % 4, cr, cg, cb)
      arc(surface, u(0, 0), v(0, 0), size * 0.62, w, (0 + r) % 4, cr, cg, cb)
      break

    case 'ring':
      ring(surface, u(0.5, 0.5), v(0.5, 0.5), size * 0.33, w, cr, cg, cb)
      break

    case 'dot':
      disc(surface, u(0.5, 0.5), v(0.5, 0.5), size * 0.26, cr, cg, cb)
      break

    case 'eye':
      disc(surface, u(0.5, 0.5), v(0.5, 0.5), size * 0.38, cr, cg, cb)
      break

    case 'diagonal':
      line(0, 0, 1, 1)
      break

    case 'cross':
      line(0, 0.5, 1, 0.5)
      line(0.5, 0, 0.5, 1)
      break

    case 'bar':
      line(0, 0.5, 1, 0.5)
      break

    case 'tee':
      line(0, 0.5, 1, 0.5)
      line(0.5, 0.5, 0.5, 1)
      break

    case 'corner':
      line(0, 0.5, 0.5, 0.5)
      line(0.5, 0.5, 0.5, 0)
      break

    case 'triangle':
      triangle(surface, u(0, 0), v(0, 0), u(1, 0), v(1, 0), u(0, 1), v(0, 1), cr, cg, cb)
      break

    case 'half': {
      // A 90° rotation maps an axis-aligned rectangle to another one, so the two
      // rotated corners bound it — no general polygon fill needed.
      const ax = u(0, 0)
      const ay = v(0, 0)
      const bx = u(1, 0.5)
      const by = v(1, 0.5)
      rect(surface, Math.min(ax, bx), Math.min(ay, by), Math.max(ax, bx), Math.max(ay, by), cr, cg, cb)
      break
    }

    case 'quarterDisc':
      // A filled quarter is a ring whose width is its own diameter: the inner
      // edge lands at zero and the arc becomes a solid wedge.
      arc(surface, u(0, 0), v(0, 0), size * 0.35, size * 0.7, (0 + r) % 4, cr, cg, cb)
      break
  }
}

export function render (ctx, spec) {
  const { width, height, palette } = spec
  const seed = spec.seed >>> 0

  const surface = new Surface(width, height)
  const [br, bg, bb] = hexToRgb(spec.background)
  surface.fill(br, bg, bb)

  const colours = palette.map(hexToRgb)
  const names = SETS[spec.set] || SETS.all

  const size = Math.max(width, height) / spec.tiles
  const cols = Math.ceil(width / size)
  const rows = Math.ceil(height / size)

  for (let cy = 0; cy < rows; cy++) {
    for (let cx = 0; cx < cols; cx++) {
      // One hash per cell, sliced into the four decisions. Four separate hashes
      // would be four times the work for no more independence than the mixing
      // already provides.
      const h = hash2(seed, cx, cy)

      const x0 = cx * size
      const y0 = cy * size

      /*
       * Some cells get a filled ground of their own, drawn under the glyph.
       *
       * This is what stops a grid of marks reading as a grid of marks: filled
       * cells clump into fields, the glyphs sit inside them, and the
       * composition acquires a large scale it does not otherwise have.
       */
      if ((h & 0xff) / 256 < spec.panel) {
        const p = colours[(h >>> 8) % colours.length]
        rect(surface, x0, y0, Math.min(width, x0 + size), Math.min(height, y0 + size), p[0], p[1], p[2], spec.panel_alpha)
      }

      if (((h >>> 16) & 0xff) / 256 >= spec.density) continue

      const name = names[(h >>> 24) % names.length]
      const rotation = (h >>> 6) & 3
      const c = colours[(h >>> 11) % colours.length]

      glyph(surface, name, rotation, x0, y0, size, c[0], c[1], c[2], spec.weight)
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
