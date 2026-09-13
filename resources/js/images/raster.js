/**
 * A tiny software rasteriser, shared by the image renderers.
 *
 * The obvious thing would be to draw with the canvas 2D path API. These renderers
 * deliberately do not, for three reasons:
 *
 *  1. A flow field is built out of a million overlapping strokes at 8% opacity.
 *     Accumulating into floats and converting once at the end keeps every one of
 *     those contributions; compositing through 8-bit canvas state quantises each
 *     stroke on the way in, and the faint end of every trail rounds to nothing.
 *  2. Additive blending — the reason a flow field glows — is available on a canvas
 *     context only as `globalCompositeOperation`, which is a per-draw state flag
 *     the browser is free to accelerate differently from machine to machine. Here
 *     the blend is four lines of arithmetic and is identical everywhere.
 *  3. It makes the renderers portable. `scripts/render-preview.mjs` renders them
 *     in Node with a nine-line shim, because createImageData and putImageData are
 *     the only two canvas calls these files make. A renderer that can only be
 *     looked at in a browser is a renderer nobody looks at.
 *
 * The cost is that antialiasing has to be written out by hand, which is the
 * `coverage` arithmetic in each of the three primitives below.
 *
 * Colours are passed as three loose numbers rather than a tuple throughout. A
 * flow field calls splat() a few million times, and an array allocated per call
 * is a few million allocations the garbage collector then has to walk.
 */

export function hexToRgb (hex) {
  return [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
  ]
}

/** Sample a list of hex stops as a continuous ramp. `t` is clamped to [0, 1]. */
export function sampleRamp (colours, t) {
  const last = colours.length - 1
  const position = Math.min(1, Math.max(0, t)) * last
  const lower = Math.floor(position)
  const upper = Math.min(last, lower + 1)
  const k = position - lower
  const a = colours[lower]
  const b = colours[upper]

  return [
    a[0] + (b[0] - a[0]) * k,
    a[1] + (b[1] - a[1]) * k,
    a[2] + (b[2] - a[2]) * k,
  ]
}

export class Surface {
  constructor (width, height) {
    this.width = width
    this.height = height
    // Float32, not Uint8: see the header. Channel values are 0-255 but are
    // allowed to run past it while accumulating, and are clamped once on output.
    this.data = new Float32Array(width * height * 3)
  }

  fill (r, g, b) {
    for (let i = 0; i < this.data.length; i += 3) {
      this.data[i] = r
      this.data[i + 1] = g
      this.data[i + 2] = b
    }
  }

  /**
   * Lay a soft round dot down at a fractional position.
   *
   * Coverage falls off linearly across the last pixel of the radius, which is
   * enough antialiasing for strokes this thin and costs one subtraction. A dot
   * narrower than a pixel gets fainter rather than wider, which is what keeps a
   * 0.4px line looking like a 0.4px line instead of a dashed one.
   */
  splat (x, y, r, g, b, alpha, radius, additive) {
    // Coverage is positive only where the pixel *centre* sits within
    // radius + 0.5 of the dot, and pixel n is centred at n + 0.5. Deriving the
    // bounds from the centres rather than the edges is worth the fiddly
    // arithmetic: it is two columns instead of five for a hairline, and this is
    // the innermost loop of the whole module.
    const x0 = Math.max(0, Math.ceil(x - radius - 1))
    const x1 = Math.min(this.width - 1, Math.floor(x + radius))
    const y0 = Math.max(0, Math.ceil(y - radius - 1))
    const y1 = Math.min(this.height - 1, Math.floor(y + radius))

    for (let py = y0; py <= y1; py++) {
      const dy = py + 0.5 - y

      for (let px = x0; px <= x1; px++) {
        const dx = px + 0.5 - x
        const coverage = radius + 0.5 - Math.sqrt(dx * dx + dy * dy)

        if (coverage <= 0) continue

        const a = alpha * Math.min(1, coverage)
        const i = (py * this.width + px) * 3

        if (additive) {
          this.data[i] += r * a
          this.data[i + 1] += g * a
          this.data[i + 2] += b * a
        } else {
          this.data[i] += (r - this.data[i]) * a
          this.data[i + 1] += (g - this.data[i + 1]) * a
          this.data[i + 2] += (b - this.data[i + 2]) * a
        }
      }
    }
  }

  /**
   * A straight segment, laid down as overlapping dots.
   *
   * Spacing is tied to the dot radius, so the dots always overlap and the line
   * is solid — stepping by the particle's own step length instead leaves a trail
   * of beads the moment the step is longer than the line is wide.
   *
   * `alpha` is ink per pixel of *length*, not per dot, and is divided by the
   * number of dots the segment needs. A long step and a short one then lay down
   * the same density, so changing the step length changes the shape of a trail
   * without also changing how dark it is.
   */
  stroke (x0, y0, x1, y1, r, g, b, alpha, radius, additive) {
    const dx = x1 - x0
    const dy = y1 - y0
    const length = Math.sqrt(dx * dx + dy * dy)
    const dots = Math.max(1, Math.ceil(length / Math.max(0.4, radius)))
    const each = alpha * (length / dots)

    for (let i = 1; i <= dots; i++) {
      const t = i / dots
      this.splat(x0 + dx * t, y0 + dy * t, r, g, b, each, radius, additive)
    }
  }

  /** Alpha-blend one whole pixel. `alpha` already carries its own coverage. */
  blend (px, py, r, g, b, alpha) {
    if (alpha <= 0 || px < 0 || py < 0 || px >= this.width || py >= this.height) return

    const i = (py * this.width + px) * 3
    const a = Math.min(1, alpha)

    this.data[i] += (r - this.data[i]) * a
    this.data[i + 1] += (g - this.data[i + 1]) * a
    this.data[i + 2] += (b - this.data[i + 2]) * a
  }

  /**
   * Clamp to 8 bits and hand the whole thing to the canvas in one call.
   *
   * Film grain — ±`grain` of per-pixel noise, docs/08 §6 — is applied here on the
   * way out rather than into the accumulation buffer. Two reasons: an animated
   * render commits the same buffer many times and would otherwise grain its own
   * grain a hundred times over, and doing it here needs no second copy of a
   * multi-megabyte float array per frame.
   *
   * The noise is an integer hash of the pixel index, not bytes from the Rng: a
   * two-megapixel frame would want two megabytes of stream, and the grain is the
   * one part of the picture where nobody could tell the difference. It still
   * comes from the seed, by way of the generator's `grain_seed`.
   *
   * The ImageData is kept between calls, because allocating a few megabytes per
   * animation frame is how you turn a smooth render into a stuttering one.
   */
  commit (ctx, grain = 0, seed = 0) {
    if (!this.image || this.image.width !== this.width) {
      this.image = ctx.createImageData(this.width, this.height)
    }

    const out = this.image.data
    const pixels = this.width * this.height
    const strength = grain * 255

    for (let i = 0, p = 0, q = 0; i < pixels; i++, p += 3, q += 4) {
      let n = 0

      if (strength) {
        // xorshift-style integer mix; imul keeps the multiply in 32 bits.
        let h = (i ^ seed) >>> 0
        h = Math.imul(h ^ (h >>> 16), 2246822507) >>> 0
        h = Math.imul(h ^ (h >>> 13), 3266489909) >>> 0
        n = ((h ^ (h >>> 16)) >>> 0) / 4294967296

        n = (n - 0.5) * strength
      }

      // Uint8ClampedArray does the rounding and the clamping on assignment,
      // which is the entire reason the accumulation buffer is allowed to run
      // past 255 in the first place.
      out[q] = this.data[p] + n
      out[q + 1] = this.data[p + 1] + n
      out[q + 2] = this.data[p + 2] + n
      out[q + 3] = 255
    }

    ctx.putImageData(this.image, 0, 0)
  }
}
