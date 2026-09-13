/**
 * Palette arithmetic the structural renderers share.
 *
 * The field renderers hand a whole Float32Array to `paintField` in shared.js and
 * never touch a colour directly. The structural ones — mazes, point sets, cells,
 * branches — colour one object at a time, so they need the sampling and shading
 * on their own terms instead.
 */

export function toRgb (palette) {
  return palette.map(hex => [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
  ])
}

/** Sample a ramp of [r,g,b] stops at t ∈ [0,1], clamped. Writes into `out` to avoid an allocation per call. */
export function rampAt (colours, t, out) {
  const last = colours.length - 1
  const position = (t < 0 ? 0 : t > 1 ? 1 : t) * last
  const lower = Math.floor(position)
  const upper = lower < last ? lower + 1 : last
  const k = position - lower
  const a = colours[lower]
  const b = colours[upper]

  out[0] = a[0] + (b[0] - a[0]) * k
  out[1] = a[1] + (b[1] - a[1]) * k
  out[2] = a[2] + (b[2] - a[2]) * k

  return out
}

/**
 * Scale a colour towards black.
 *
 * Used for ink and for the ground behind a drawing, both of which want to belong
 * to the generated palette rather than being a hardcoded grey that fights it —
 * a maze drawn in #111 on a viridis floor looks like two pictures.
 */
export function shade (rgb, factor) {
  return [rgb[0] * factor, rgb[1] * factor, rgb[2] * factor]
}
