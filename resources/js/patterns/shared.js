/**
 * The parts every field-based renderer would otherwise copy.
 *
 * Perlin was written before there was a second canvas generator to share with, so
 * it still carries its own copies; everything since draws from here. The two
 * things worth centralising turned out to be the normalise-and-colour pass, which
 * has a bug in it that is invisible until it bites (see paintField), and the cell
 * hash, which is the alternative to drawing a random number per feature point.
 */

export function hexToRgb (hex) {
  return [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
  ]
}

/**
 * A 32-bit integer hash of a grid coordinate.
 *
 * Feature points, automaton start rows and reaction seeds all need "a random
 * number for cell (x, y)" — and drawing them from the Rng in scan order would be
 * both position-dependent (pan the field and every point renumbers) and capable
 * of exhausting the 8 KB render stream on a grid of any size. Hashing instead
 * keeps the stream to a single uint32 for the whole generator.
 *
 * The constants are the xxHash/murmur mixing primes; Math.imul is what keeps the
 * multiply in 32-bit space, since JS numbers would otherwise lose the low bits
 * that carry all the mixing.
 */
export function hash2 (seed, x, y) {
  let h = (seed ^ Math.imul(x | 0, 0x27d4eb2d) ^ Math.imul(y | 0, 0x165667b1)) | 0
  h = Math.imul(h ^ (h >>> 15), 0x2c1b3c6d)
  h = Math.imul(h ^ (h >>> 13), 0x297a2d39)
  return (h ^ (h >>> 16)) >>> 0
}

/** The same hash as a float in [0, 1). */
export function hashFloat (seed, x, y) {
  return hash2(seed, x, y) / 4294967296
}

/**
 * Measure a field, stretch it across the palette, and write the pixels.
 *
 * Two passes, because no fixed remap survives a parameter change: a Worley F₂−F₁
 * field peaks around 0.4 at one density and 0.05 at another, and a Gray–Scott v
 * field may never leave [0, 0.3]. Measuring and then stretching means the palette
 * is used end to end whatever the sliders say — the difference between a picture
 * and a flat wash.
 *
 * `field` must be a Float32Array. The min/max are read back *out* of it rather
 * than tracked as the doubles that went in: storing rounds to float32, so a double
 * compared beforehand can sit a hair outside the range the stored values occupy,
 * and a t of -1e-7 floors to index -1. `colours[-1]` is undefined, not the first
 * colour, and the whole image comes out as NaN pixels.
 */
export function paintField (ctx, field, width, height, colours, { contours = false, invert = false } = {}) {
  let min = Infinity
  let max = -Infinity

  for (let i = 0; i < field.length; i++) {
    const v = field[i]
    if (v < min) min = v
    if (v > max) max = v
  }

  // A perfectly uniform field — a reaction that died, a scatter of zero — would
  // divide by zero; paint it the middle of the ramp rather than NaN.
  const span = max - min || 1
  const last = colours.length - 1

  const image = ctx.createImageData(width, height)
  const data = image.data

  for (let i = 0, p = 0; i < field.length; i++) {
    let t = (field[i] - min) / span
    if (invert) t = 1 - t

    let r, g, b
    if (contours) {
      const c = colours[Math.min(last, Math.floor(t * (last + 1)))]
      r = c[0]; g = c[1]; b = c[2]
    } else {
      const position = t * last
      const lower = Math.floor(position)
      const upper = Math.min(last, lower + 1)
      const k = position - lower
      const a = colours[lower]
      const c = colours[upper]
      r = a[0] + (c[0] - a[0]) * k
      g = a[1] + (c[1] - a[1]) * k
      b = a[2] + (c[2] - a[2]) * k
    }

    data[p++] = r
    data[p++] = g
    data[p++] = b
    data[p++] = 255
  }

  ctx.putImageData(image, 0, 0)

  return { min, max }
}
