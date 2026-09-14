/**
 * Antialiased primitives the compositional renderers share.
 *
 * `raster.js` gives a Surface a soft dot and a stroke, which is everything a
 * flow field needs. The generators that compose *shapes* — packed circles,
 * subdivided rectangles, glyph cells, banded strata — need filled areas with
 * clean edges instead, and they all need the same three.
 *
 * These live here rather than as Surface methods for one reason: raster.js is
 * load-bearing for renderers that already ship, and adding to a file is a worse
 * trade than adding a file next to it when the new code has no existing caller.
 *
 * Every primitive takes loose channel numbers rather than a colour tuple, for
 * the reason raster.js gives: these run often enough that an array per call is
 * an allocation per call.
 *
 * The coverage arithmetic is the same everywhere — a signed distance to the
 * shape's edge, clamped to [0, 1] across the last pixel — so a shape narrower
 * than a pixel comes out fainter rather than disappearing or jumping to a full
 * pixel wide.
 */

/**
 * A filled disc.
 *
 * Surface.splat already draws exactly this, and is used directly where a shape
 * is opaque. The wrapper exists so the shape renderers read the same way for all
 * three primitives, and so the caller does not have to remember that `additive`
 * is the last of seven positional arguments.
 */
export function disc (surface, x, y, radius, r, g, b, alpha = 1) {
  surface.splat(x, y, r, g, b, alpha, radius, false)
}

/**
 * An annulus of a given stroke width, centred on `radius`.
 *
 * Drawn as one shape rather than as a filled disc with a background-coloured
 * disc punched out of it. The punch-out version is a pixel wider on the inside
 * than it should be, cannot be drawn over anything but a flat ground, and
 * double-blends its own antialiased inner edge — three bugs for one saved
 * function.
 */
export function ring (surface, x, y, radius, width, r, g, b, alpha = 1) {
  const outer = radius + width / 2
  const inner = radius - width / 2

  const x0 = Math.max(0, Math.ceil(x - outer - 1))
  const x1 = Math.min(surface.width - 1, Math.floor(x + outer))
  const y0 = Math.max(0, Math.ceil(y - outer - 1))
  const y1 = Math.min(surface.height - 1, Math.floor(y + outer))

  for (let py = y0; py <= y1; py++) {
    const dy = py + 0.5 - y

    for (let px = x0; px <= x1; px++) {
      const dx = px + 0.5 - x
      const d = Math.sqrt(dx * dx + dy * dy)

      // Inside the outer edge and outside the inner one, whichever is closer.
      const coverage = Math.min(outer + 0.5 - d, d - inner + 0.5)

      if (coverage <= 0) continue

      surface.blend(px, py, r, g, b, alpha * Math.min(1, coverage))
    }
  }
}

/**
 * An axis-aligned filled rectangle, with fractional edges.
 *
 * Fractional matters more than it sounds. A Mondrian subdivision splits at
 * U(0.3, 0.7) of a rectangle that has itself already been split twice, so the
 * edges land wherever they land; rounding each one to a pixel makes a two-pixel
 * gutter one pixel here and three there, and the eye reads that as a mistake
 * long before it can say what it is looking at.
 */
export function rect (surface, x0, y0, x1, y1, r, g, b, alpha = 1) {
  const left = Math.max(0, Math.floor(x0))
  const right = Math.min(surface.width - 1, Math.ceil(x1) - 1)
  const top = Math.max(0, Math.floor(y0))
  const bottom = Math.min(surface.height - 1, Math.ceil(y1) - 1)

  for (let py = top; py <= bottom; py++) {
    // How much of this pixel row the rectangle covers vertically: the overlap of
    // [py, py+1] with [y0, y1].
    const cy = Math.min(py + 1, y1) - Math.max(py, y0)
    if (cy <= 0) continue

    for (let px = left; px <= right; px++) {
      const cx = Math.min(px + 1, x1) - Math.max(px, x0)
      if (cx <= 0) continue

      surface.blend(px, py, r, g, b, alpha * cx * cy)
    }
  }
}

/**
 * A straight stroke with rounded ends, at full opacity.
 *
 * Surface.stroke exists and is the wrong tool here. Its `alpha` is ink per pixel
 * of *length*, divided across the dots it lays down — exactly right for a flow
 * field, where a trail's darkness must not change when its step length does, and
 * exactly wrong for a glyph, where the line is simply meant to be solid. Passing
 * it 1 gives each dot an alpha of several units, which overshoots the blend and
 * paints a chain of white and black beads. That was the first render of the line
 * glyphs.
 *
 * So this measures distance to the segment instead and shades from it, which is
 * both correct at any width and cheaper than laying down overlapping dots.
 */
export function segment (surface, x0, y0, x1, y1, width, r, g, b, alpha = 1) {
  const half = width / 2

  const left = Math.max(0, Math.floor(Math.min(x0, x1) - half - 1))
  const right = Math.min(surface.width - 1, Math.ceil(Math.max(x0, x1) + half + 1))
  const top = Math.max(0, Math.floor(Math.min(y0, y1) - half - 1))
  const bottom = Math.min(surface.height - 1, Math.ceil(Math.max(y0, y1) + half + 1))

  const dx = x1 - x0
  const dy = y1 - y0
  // A zero-length segment is a dot; the clamp below then pins t at 0 and the
  // distance becomes the distance to the single endpoint.
  const lengthSquared = dx * dx + dy * dy || 1

  for (let py = top; py <= bottom; py++) {
    for (let px = left; px <= right; px++) {
      const ox = px + 0.5 - x0
      const oy = py + 0.5 - y0

      // Where along the segment the nearest point lies, clamped to its ends —
      // which is what turns an infinite line into a capsule.
      let t = (ox * dx + oy * dy) / lengthSquared
      if (t < 0) t = 0
      else if (t > 1) t = 1

      const ex = ox - dx * t
      const ey = oy - dy * t
      const coverage = half + 0.5 - Math.sqrt(ex * ex + ey * ey)

      if (coverage <= 0) continue

      surface.blend(px, py, r, g, b, alpha * Math.min(1, coverage))
    }
  }
}

/**
 * A quarter-circle arc, for the glyph set.
 *
 * Stroked by the same signed-distance test as `ring`, restricted to one quadrant
 * about (cx, cy). Sweeping dots along the arc would be the obvious alternative
 * and leaves a scalloped edge wherever the spacing and the stroke width do not
 * agree; a distance test is both simpler and exact.
 */
export function arc (surface, cx, cy, radius, width, quadrant, r, g, b, alpha = 1) {
  const outer = radius + width / 2
  const inner = radius - width / 2

  // Which signs of (dx, dy) the quadrant occupies: 0 is down-right, then
  // anticlockwise in the screen's coordinates.
  const sx = (quadrant === 0 || quadrant === 3) ? 1 : -1
  const sy = (quadrant === 0 || quadrant === 1) ? 1 : -1

  const x0 = Math.max(0, Math.ceil(Math.min(cx, cx + sx * outer) - 1))
  const x1 = Math.min(surface.width - 1, Math.floor(Math.max(cx, cx + sx * outer)))
  const y0 = Math.max(0, Math.ceil(Math.min(cy, cy + sy * outer) - 1))
  const y1 = Math.min(surface.height - 1, Math.floor(Math.max(cy, cy + sy * outer)))

  for (let py = y0; py <= y1; py++) {
    const dy = (py + 0.5 - cy) * sy

    for (let px = x0; px <= x1; px++) {
      const dx = (px + 0.5 - cx) * sx
      if (dx < -0.5 || dy < -0.5) continue

      const d = Math.sqrt(dx * dx + dy * dy)
      const coverage = Math.min(outer + 0.5 - d, d - inner + 0.5)

      if (coverage <= 0) continue

      surface.blend(px, py, r, g, b, alpha * Math.min(1, coverage))
    }
  }
}

/**
 * A filled triangle, given three corners.
 *
 * Sampled by barycentric sign test at pixel centres, with the edge softened over
 * one pixel by the smallest of the three edge distances. Enough antialiasing for
 * a shape this size, and about a fifth of the code a proper scanline rasteriser
 * with analytic coverage would need.
 */
export function triangle (surface, ax, ay, bx, by, cx, cy, r, g, b, alpha = 1) {
  const x0 = Math.max(0, Math.floor(Math.min(ax, bx, cx)) - 1)
  const x1 = Math.min(surface.width - 1, Math.ceil(Math.max(ax, bx, cx)) + 1)
  const y0 = Math.max(0, Math.floor(Math.min(ay, by, cy)) - 1)
  const y1 = Math.min(surface.height - 1, Math.ceil(Math.max(ay, by, cy)) + 1)

  const area = (bx - ax) * (cy - ay) - (by - ay) * (cx - ax)
  if (area === 0) return

  // Normalised edge functions, so the value at a point is its signed distance to
  // that edge in pixels rather than in twice-the-area units.
  const edges = [
    [ax, ay, bx, by],
    [bx, by, cx, cy],
    [cx, cy, ax, ay],
  ].map(([px, py, qx, qy]) => {
    const ex = qx - px
    const ey = qy - py
    const length = Math.hypot(ex, ey) || 1

    return [px, py, ex / length, ey / length]
  })

  const winding = area > 0 ? 1 : -1

  for (let py = y0; py <= y1; py++) {
    for (let px = x0; px <= x1; px++) {
      let coverage = Infinity

      for (const [ex0, ey0, ex, ey] of edges) {
        const d = ((px + 0.5 - ex0) * ey - (py + 0.5 - ey0) * ex) * -winding
        if (d < coverage) coverage = d
      }

      coverage += 0.5
      if (coverage <= 0) continue

      surface.blend(px, py, r, g, b, alpha * Math.min(1, coverage))
    }
  }
}
