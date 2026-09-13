/**
 * Worley / cellular noise.
 *
 * One feature point per grid cell, and every pixel keeps the distance to the
 * nearest one (F₁) and the next nearest (F₂). Everything interesting is a
 * combination of those two numbers:
 *
 *   F₁        distance to nearest       → bubbles, scales
 *   F₂        distance to second        → the shadow of the same cells
 *   F₂ − F₁   zero exactly on a cell boundary → cracked mud, veins
 *   F₁ · F₂   a soft falloff either side of the boundary
 *
 * The metric is what changes the shape of a cell: Euclidean rounds it, Manhattan
 * cuts it into diamonds, Chebyshev squares it off, and Minkowski-p walks between
 * them.
 */
import { hash2, hexToRgb, paintField } from './shared.js'

/**
 * Where cell (ix, iy) keeps its point, in cell units.
 *
 * The hash gives 32 bits; the two halves are far more resolution than a cell a
 * few pixels wide can show. `jitter` shrinks the offset towards the cell centre,
 * and at 0 the points form a perfect lattice — worth keeping as a control, since
 * seeing the regular tiling is what makes it obvious the randomness is doing all
 * the work.
 */
function point (seed, ix, iy, jitter, out) {
  const h = hash2(seed, ix, iy)
  out[0] = ix + 0.5 + (((h >>> 16) / 65536) - 0.5) * jitter
  out[1] = iy + 0.5 + (((h & 0xffff) / 65536) - 0.5) * jitter
}

/*
 * Distance is computed in two halves: a *monotone* form in the inner loop and
 * the finishing root once, at the end.
 *
 * Ordering by dx²+dy² gives the same F₁ and F₂ as ordering by √(dx²+dy²), so the
 * twenty-five square roots per pixel collapse to two. For Minkowski that is the
 * difference between three pow() calls per neighbour and two, and pow is roughly
 * two orders of magnitude slower than a multiply — at 25 evaluations per pixel it
 * is the whole cost of the renderer.
 *
 * The three named metrics are special-cased rather than routed through Minkowski
 * with p = 1, 2, ∞ for the same reason.
 */
const EUCLIDEAN = 0
const MANHATTAN = 1
const CHEBYSHEV = 2
const MINKOWSKI = 3

const CODES = { euclidean: EUCLIDEAN, manhattan: MANHATTAN, chebyshev: CHEBYSHEV, minkowski: MINKOWSKI }

export function render (ctx, spec, rng) {
  const { width, height, palette, jitter, feature, contours, invert } = spec

  const seed = spec.seed >>> 0
  const code = CODES[spec.metric] ?? EUCLIDEAN
  // A p at or below zero is not a metric at all and would hand pow() an infinity;
  // the schema already stops at 0.4, and this is the belt to that pair of braces.
  const p = Math.max(0.1, spec.p)
  const inverseP = 1 / p

  const colours = palette.map(hexToRgb)
  const step = spec.density / Math.max(width, height)
  const [ox, oy] = spec.offset

  const field = new Float32Array(width * height)
  const q = [0, 0]

  /*
   * A two-ring neighbourhood, not the usual one.
   *
   * 3×3 is exact for F₁ and very nearly exact for F₂ under Euclidean, but the
   * other metrics stretch a cell's reach: under Chebyshev a point two columns
   * away can be nearer than one diagonally adjacent. Getting F₂ wrong is not a
   * subtle error in the F₂−F₁ mode — it puts a hard seam across the image — so
   * the extra sixteen cells are cheap insurance.
   */
  let f = 0
  for (let y = 0; y < height; y++) {
    const ny = y * step + oy
    const cy = Math.floor(ny)

    for (let x = 0; x < width; x++) {
      const nx = x * step + ox
      const cx = Math.floor(nx)

      let f1 = Infinity
      let f2 = Infinity

      for (let gy = -2; gy <= 2; gy++) {
        for (let gx = -2; gx <= 2; gx++) {
          point(seed, cx + gx, cy + gy, jitter, q)

          const dx = q[0] - nx
          const dy = q[1] - ny
          const ax = dx < 0 ? -dx : dx
          const ay = dy < 0 ? -dy : dy

          let d
          switch (code) {
            case MANHATTAN: d = ax + ay; break
            case CHEBYSHEV: d = ax > ay ? ax : ay; break
            case MINKOWSKI: d = ax ** p + ay ** p; break
            default: d = dx * dx + dy * dy
          }

          if (d < f1) {
            f2 = f1
            f1 = d
          } else if (d < f2) {
            f2 = d
          }
        }
      }

      if (code === EUCLIDEAN) {
        f1 = Math.sqrt(f1)
        f2 = Math.sqrt(f2)
      } else if (code === MINKOWSKI) {
        f1 **= inverseP
        f2 **= inverseP
      }

      switch (feature) {
        case 'f2': field[f++] = f2; break
        case 'f2f1': field[f++] = f2 - f1; break
        case 'f1f2': field[f++] = f1 * f2; break
        default: field[f++] = f1
      }
    }
  }

  paintField(ctx, field, width, height, colours, { contours, invert })
}
