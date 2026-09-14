/**
 * OpenSimplex2, 2D — gradient noise on a triangular lattice.
 *
 * Perlin interpolates over a square, and a square has axes. Every feature of a
 * Perlin field is therefore nudged towards 0°, 45° and 90°, which is why a large
 * Perlin canvas always looks faintly like graph paper. A simplex lattice has no
 * such preferred directions: the plane is tiled with equilateral triangles, each
 * sample sums contributions from the three or four nearest lattice points, and
 * the result is isotropic.
 *
 *     skew:    s = (x + y) · F₂          F₂ = (√3 − 1)/2
 *     unskew:  t = (i + j) · G₂          G₂ = (3 − √3)/6  (here, −0.2113 = −G₂)
 *     value    = Σ  max(0, r² − |d|²)⁴ · (g · d)
 *
 * **OpenSimplex2 rather than classic simplex**, as docs/07 §1.3 asks. Two
 * reasons, one legal and one aesthetic: it sidesteps the Perlin-simplex patent
 * lineage entirely, and it replaces classic simplex's cubic falloff and twelve
 * gradients with a quartic falloff and twenty-four, which is what removes the
 * faint blotchiness classic simplex still shows at low frequency.
 *
 * One deliberate deviation from KdotJPG's reference: gradients are selected with
 * the project's own 32-bit `hash2` instead of the reference's 64-bit prime
 * multiply. JavaScript has no 64-bit integer multiply that stays exact in a
 * Number, and the alternative — BigInt in the innermost loop of a half-million
 * pixel render — is not a trade worth making. The lattice, the kernel and the
 * gradient set are unchanged; only the source of "which of the 24 gradients does
 * this lattice point hold" differs, and any hash that mixes well serves there.
 */
import { fractal as perlinFractal } from './perlin.js'
import { hash2, hexToRgb, paintField } from './shared.js'

const SKEW = 0.366025403784439      // (√3 − 1) / 2
const UNSKEW = -0.211324865405187   // −(3 − √3) / 6
const RSQUARED = 0.5                // kernel radius², the OpenSimplex2 (fast) value

/**
 * Scale that brings the summed kernel back to roughly [−1, 1].
 *
 * KdotJPG's measured normaliser for the 2D case, paired with r² = 1/2 — the two
 * go together, and taking the smooth variant's r² = 2/3 with this constant puts
 * the field five times outside [−1, 1], which every shaping variant below then
 * folds wrongly. The two-pass normalise in
 * paintField would make the absolute scale irrelevant for a plain fBm, but the
 * `turbulence`, `ridged` and `billow` variants all fold around fixed constants —
 * |n|, 1 − |n|, 2|n| − 1 — so the field has to actually occupy [−1, 1] for those
 * to mean what they mean.
 */
const NORMALISER = 1 / 0.01001634121365712

/**
 * The 24 gradient directions, at 7.5° + k·15°.
 *
 * Twenty-four evenly spaced unit vectors rather than Perlin's eight axis-and-
 * diagonal ones. Eight directions is itself a source of anisotropy — it is the
 * *second* reason a Perlin field looks square, after the lattice — and a 24-gon
 * is fine enough that no direction is favoured at any scale the eye resolves.
 */
const GRADIENTS = new Float64Array(48)

for (let k = 0; k < 24; k++) {
  const angle = (7.5 + k * 15) * Math.PI / 180
  GRADIENTS[k * 2] = Math.cos(angle)
  GRADIENTS[k * 2 + 1] = Math.sin(angle)
}

/** One lattice point's contribution: the kernel falloff times the gradient dot product. */
function contribution (seed, xsb, ysb, dx, dy) {
  const a = RSQUARED - dx * dx - dy * dy

  if (a <= 0) return 0

  const g = (hash2(seed, xsb, ysb) % 24) * 2

  // a⁴ rather than a³: OpenSimplex2's quartic kernel is what buys the extra
  // smoothness over classic simplex, at the cost of one more multiply.
  return (a * a) * (a * a) * (GRADIENTS[g] * dx + GRADIENTS[g + 1] * dy)
}

/**
 * OpenSimplex2 noise at (x, y). Roughly [−1, 1].
 *
 * The four branches below are the reference implementation's triangle selection,
 * written out rather than derived: having skewed into lattice space and unskewed
 * the offsets, the sample sits in one of two triangles of the rhombus, and which
 * of the two further lattice points are within kernel range depends on where in
 * that triangle it falls. Getting a branch wrong is not subtle — it shows up as
 * hard creases along the lattice diagonals.
 */
export function simplex2 (seed, x, y) {
  // Skew the input into lattice space, then take the rhombus it falls in.
  const s = (x + y) * SKEW
  const xsb = Math.floor(x + s)
  const ysb = Math.floor(y + s)

  const xi = x + s - xsb
  const yi = y + s - ysb

  // Unskew the in-cell offsets back into real space.
  const t = (xi + yi) * UNSKEW
  const dx0 = xi + t
  const dy0 = yi + t

  // The two ends of the rhombus's short diagonal, then whichever two of the
  // surrounding lattice points the kernel can still reach. `contribution`
  // returns zero for anything out of range, so all four go through one path.
  let value = contribution(seed, xsb, ysb, dx0, dy0)
  value += contribution(seed, xsb + 1, ysb + 1, dx0 - (1 + 2 * UNSKEW), dy0 - (1 + 2 * UNSKEW))

  const xmyi = xi - yi

  if (t < UNSKEW) {
    // Upper triangle: xi + yi > 1, because UNSKEW is negative.
    if (xi + xmyi > 1) {
      value += contribution(seed, xsb + 2, ysb + 1, dx0 - (3 * UNSKEW + 2), dy0 - (3 * UNSKEW + 1))
    } else {
      value += contribution(seed, xsb, ysb + 1, dx0 - UNSKEW, dy0 - (UNSKEW + 1))
    }

    if (yi - xmyi > 1) {
      value += contribution(seed, xsb + 1, ysb + 2, dx0 - (3 * UNSKEW + 1), dy0 - (3 * UNSKEW + 2))
    } else {
      value += contribution(seed, xsb + 1, ysb, dx0 - (UNSKEW + 1), dy0 - UNSKEW)
    }
  } else {
    // Lower triangle.
    if (xi + xmyi < 0) {
      value += contribution(seed, xsb - 1, ysb, dx0 + (1 + UNSKEW), dy0 + UNSKEW)
    } else {
      value += contribution(seed, xsb + 1, ysb, dx0 - (1 + UNSKEW), dy0 - UNSKEW)
    }

    if (yi < xmyi) {
      value += contribution(seed, xsb, ysb - 1, dx0 + UNSKEW, dy0 + (1 + UNSKEW))
    } else {
      value += contribution(seed, xsb, ysb + 1, dx0 - UNSKEW, dy0 - (1 + UNSKEW))
    }
  }

  return value * NORMALISER
}

/**
 * Octave stack, with the same four shaping variants patterns.perlin offers.
 *
 * Identical arithmetic to perlin.js's `fractal`, deliberately: the whole claim
 * this generator makes is that the *lattice* is the difference, and that claim is
 * only legible if everything downstream of the base noise is held constant.
 */
export function fractal2 (seed, x, y, { octaves, persistence, lacunarity, variant }) {
  let amplitude = 1
  let frequency = 1
  let sum = 0
  let total = 0

  for (let o = 0; o < octaves; o++) {
    // Each octave gets its own lattice seed; reusing one would make every octave
    // share its zero crossings and stack into a visible cross-hatch.
    const n = simplex2((seed + o * 0x9e3779b9) | 0, x * frequency, y * frequency)

    let shaped
    switch (variant) {
      case 'turbulence': shaped = Math.abs(n); break
      case 'ridged': { const r = 1 - Math.abs(n); shaped = r * r; break }
      case 'billow': shaped = 2 * Math.abs(n) - 1; break
      default: shaped = n
    }

    sum += shaped * amplitude
    total += amplitude
    amplitude *= persistence
    frequency *= lacunarity
  }

  return sum / total
}

/** Fold the field through a copy of itself, as patterns.perlin does. */
function warped (seed, x, y, spec) {
  if (spec.warp <= 0) return fractal2(seed, x, y, spec)

  const qx = fractal2(seed, x, y, spec)
  const qy = fractal2(seed, x + 5.2, y + 1.3, spec)

  return fractal2(seed, x + spec.warp * qx * 4, y + spec.warp * qy * 4, spec)
}

/**
 * How much finer an OpenSimplex2 field is than a Perlin field at the same
 * frequency, measured by counting zero crossings along a long line through both:
 * 282 against 193 over the same 200 lattice units.
 *
 * Only used by comparison mode, and applied to the *Perlin* side, so that
 * toggling the comparison on never changes the half of the picture the generator
 * is actually about. Without it the eye reads the difference as "one side is
 * finer" instead of "one side is square", which is not the claim.
 */
const PERLIN_FREQUENCY_MATCH = 1.461

export function render (ctx, spec, rng) {
  const { width, height, palette } = spec
  const seed = spec.seed >>> 0
  const colours = palette.map(hexToRgb)

  const step = spec.scale / Math.max(width, height)
  const [ox, oy] = spec.offset

  const split = spec.compare ? Math.floor(width / 2) : width
  const perm = spec.compare ? rng.permutation() : null
  const match = PERLIN_FREQUENCY_MATCH

  const field = new Float32Array(width * height)

  for (let y = 0, f = 0; y < height; y++) {
    const ny = y * step + oy

    for (let x = 0; x < width; x++, f++) {
      field[f] = x < split
        ? warped(seed, x * step + ox, ny, spec)
        : perlinFractal(perm, (x * step + ox) * match, ny * match, spec)
    }
  }

  if (spec.compare) {
    /*
     * Stretch each half to its own range before colouring.
     *
     * The first build normalised the two together, which sounds like the honest
     * choice and is not: Perlin's amplitude distribution is narrower than
     * OpenSimplex2's, so the right half came out as a flat wash and the picture
     * said "simplex has more contrast" — which is a fact about the two
     * normalising constants, not about the lattice. Equalising amplitude is the
     * control that leaves *shape* as the only difference on show, which is the
     * whole claim.
     */
    normaliseBand(field, width, height, 0, split)
    normaliseBand(field, width, height, split, width)

    // A two-pixel seam, painted the palette's darkest stop by pinning it to the
    // bottom of the now-common [0, 1] range. Drawn into the field rather than
    // over the finished pixels: paintField owns the only putImageData here, and
    // the render-preview shim keeps exactly one ImageData, so a second write
    // would replace the picture rather than overlay it.
    for (let y = 0; y < height; y++) {
      field[y * width + split] = 0
      field[y * width + split - 1] = 0
    }
  }

  paintField(ctx, field, width, height, colours, { contours: spec.contours })
}

/**
 * Rescale one vertical band of the field into [0, 1], in place.
 *
 * Min and max are read back *out* of the Float32Array rather than tracked as the
 * doubles that went in — storing rounds to float32, and a double compared
 * beforehand can sit a hair outside the range the stored values occupy. docs/07
 * §9 has the rest of that story.
 */
function normaliseBand (field, width, height, from, to) {
  let min = Infinity
  let max = -Infinity

  for (let y = 0; y < height; y++) {
    for (let x = from; x < to; x++) {
      const v = field[y * width + x]
      if (v < min) min = v
      if (v > max) max = v
    }
  }

  const span = max - min || 1

  for (let y = 0; y < height; y++) {
    for (let x = from; x < to; x++) {
      field[y * width + x] = (field[y * width + x] - min) / span
    }
  }
}
