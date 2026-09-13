/**
 * The avatar, drawn from a grid the server already folded.
 *
 * There is no randomness in this file and none in the generator behind it: the
 * grid, the two colours and the cell shape are all read out of `sha256(input)`.
 * Feed it the same string on any machine in any year and the same face comes
 * back, with nothing stored anywhere. That is the entire demonstration.
 *
 * The only thing worth a comment is the mirroring, which the server does to the
 * grid but this file finishes: the cell shape is mirrored too, so a triangle in
 * the left half points the other way in the right half. Without that the layout
 * is symmetric while the ink inside it is not, and the eye reads the mismatch
 * long before it works out why.
 */
import { Surface, hexToRgb } from './raster.js'

export function bytesNeeded () {
  return 1024
}

/**
 * Signed distance from a point to the cell shape, in cell units.
 *
 * Negative inside, positive outside, and near enough to a true distance close to
 * the boundary that `0.5 − d·pixels` is a usable coverage value.
 */
function distance (shape, u, v) {
  switch (shape) {
    case 'circle': {
      const dx = u - 0.5
      const dy = v - 0.5
      return Math.sqrt(dx * dx + dy * dy) - 0.5
    }

    case 'diamond':
      // |u| + |v| is the L1 distance; dividing by √2 converts it to the
      // Euclidean distance from the edge, which is what antialiasing wants.
      return (Math.abs(u - 0.5) + Math.abs(v - 0.5) - 0.5) / Math.SQRT2

    case 'triangle':
      // A corner triangle rather than a centred one, precisely because it is not
      // symmetric under the mirror. The three half-planes are u ≥ 0, v ≥ 0 and
      // u + v ≤ 1.
      return Math.max(-u, -v, (u + v - 1) / Math.SQRT2)

    default:
      // Square: distance to the nearer of the two axes' edges.
      return Math.max(Math.abs(u - 0.5), Math.abs(v - 0.5)) - 0.5
  }
}

export function render (ctx, spec) {
  const surface = new Surface(spec.width, spec.height)
  const [br, bg, bb] = hexToRgb(spec.background)
  surface.fill(br, bg, bb)

  const colours = spec.colours.map(hexToRgb)
  const shortest = Math.min(spec.width, spec.height)
  const inner = shortest * (1 - 2 * spec.padding)
  const cell = inner / spec.cells
  const inset = (spec.gap * cell) / 2
  const size = cell - 2 * inset

  const originX = (spec.width - inner) / 2
  const originY = (spec.height - inner) / 2
  const middle = Math.floor(spec.cells / 2)

  for (let row = 0; row < spec.cells; row++) {
    for (let column = 0; column < spec.cells; column++) {
      const value = spec.grid[row * spec.cells + column]

      if (!value) continue

      const [r, g, b] = colours[Math.min(colours.length - 1, value - 1)]
      const mirrored = column > middle

      const left = originX + column * cell + inset
      const top = originY + row * cell + inset

      const x0 = Math.max(0, Math.floor(left - 1))
      const x1 = Math.min(spec.width - 1, Math.ceil(left + size + 1))
      const y0 = Math.max(0, Math.floor(top - 1))
      const y1 = Math.min(spec.height - 1, Math.ceil(top + size + 1))

      for (let py = y0; py <= y1; py++) {
        const v = (py + 0.5 - top) / size

        for (let px = x0; px <= x1; px++) {
          let u = (px + 0.5 - left) / size
          if (mirrored) u = 1 - u

          const coverage = Math.min(1, 0.5 - distance(spec.shape, u, v) * size)

          if (coverage > 0) surface.blend(px, py, r, g, b, coverage)
        }
      }
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
