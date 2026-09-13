/**
 * The superformula, drawn as a field rather than as a path.
 *
 *     r(φ) = ( |cos(mφ/4) / a|^n₂ + |sin(mφ/4) / b|^n₃ )^(−1/n₁)
 *
 * The usual way to draw this is to sample φ, build a polygon and stroke it. This
 * does the opposite: for every pixel it works out φ and asks the equation how far
 * the boundary is in that direction. Superformula shapes are star-shaped about
 * their centre — the boundary crosses every ray from the centre exactly once — so
 * that inversion is exact, not an approximation.
 *
 * What it buys is that `rr − r(φ)` is a signed distance. Antialiasing, outlines
 * and translucent fills all fall out of one number, and a shape with five hundred
 * spikes costs no more than a circle and stays crisp at every one of them, where
 * a polygon would have to be sampled finely enough to find them all.
 *
 * Every shape arrives fully specified from the server, so nothing here reads the
 * Rng. The seed already did its work choosing the exponents.
 *
 * One thing the server guarantees and this file relies on: the curve closes.
 * `phi` comes from atan2, so it jumps from +π to −π along the negative x axis,
 * and a curve whose r(φ) differs across a full turn shows that jump as a hard
 * radial seam. See BlobGenerator::drawShape for what keeps it closed.
 */
import { Surface, hexToRgb } from './raster.js'

/** Nothing is drawn from the stream; this is the floor the harness derives. */
export function bytesNeeded () {
  return 1024
}

/*
 * Scratch space for boundary(), which has two numbers to report and is called
 * once per pixel of every shape. Returning a tuple there would allocate a few
 * million arrays for no reason at all.
 */
const OUT = new Float64Array(2)

/**
 * Peak opacity of a specimen's halo, at the silhouette itself.
 *
 * Low, because it is painted in the palette's brightest stop and haloes overlap
 * wherever two specimens are close. Much above this and the overlaps stack into
 * a flat wash that erases the negative space the composition is built on.
 */
const GLOW_ALPHA = 0.17

/**
 * The boundary radius at φ, and how steeply it is changing there.
 *
 * The slope is the part that is not obvious, and skipping it is a visible bug.
 * `rr − r(φ)` is a signed distance only where the boundary runs perpendicular to
 * the ray; along the flank of a spike the curve is nearly *parallel* to the ray
 * and that difference overstates the true distance enormously. Antialias with it
 * unaltered and every spike gets a dotted outline, because the one-pixel coverage
 * band collapses to a fraction of a pixel and falls between the samples.
 *
 * For a polar curve the correction is exact to first order: the true distance is
 * the radial difference times cos ψ, where tan ψ = r′/r is the angle between the
 * ray and the curve's normal. Both derivatives reuse powers already computed, so
 * the whole correction costs two divisions.
 */
function boundary (phi, m, n1, n2, n3) {
  const t = m * phi / 4

  // a = b = 1, per docs/08 §2. The stretch that makes a shape oval is applied
  // afterwards as a plain scale, so the curve stays the published one.
  const c = Math.cos(t)
  const s = Math.sin(t)
  const ac = Math.abs(c)
  const as = Math.abs(s)

  const pc = Math.pow(ac, n2)
  const ps = Math.pow(as, n3)
  const term = pc + ps

  OUT[0] = Math.pow(term, -1 / n1)

  // d(term)/dφ, using n·x^(n−1) = n·x^n / x to avoid a second pair of pows.
  // At a cusp (|cos| or |sin| at zero) that quotient is unbounded; the term it
  // belongs to has already gone to zero there, so it contributes nothing.
  const dterm = (m / 4) * (
    (ac > 1e-9 ? (n2 * pc / ac) * -s * Math.sign(c) : 0) +
    (as > 1e-9 ? (n3 * ps / as) * c * Math.sign(s) : 0)
  )

  const ratio = term > 0 ? dterm / (n1 * term) : 0
  OUT[1] = 1 / Math.sqrt(1 + ratio * ratio)

  return OUT
}

function drawShape (surface, shape) {
  const { cx, cy, sx, sy, m, n1, n2, n3, rmax, rot } = shape

  const [fr, fg, fb] = hexToRgb(shape.fill)
  const [er, eg, eb] = hexToRgb(shape.edge)
  const [kr, kg, kb] = hexToRgb(shape.stroke)

  // The band is a two-stop gradient: `fill` at `grad` of the way out from the
  // centre, `edge` at the boundary. Consecutive bands share a stop, so a whole
  // specimen is one continuous radial ramp with the band silhouettes cut into
  // it. Flat per-band colour is what made this read as a stack of hoops.
  const inner = shape.grad || 0
  const span = 1 - inner

  // Rotating the sample point by −rot is the same as rotating the shape by +rot,
  // and costs two trig calls for the whole shape instead of one per boundary
  // sample.
  const cos = Math.cos(-rot)
  const sin = Math.sin(-rot)

  const half = shape.line / 2

  // The halo, in pixels of boundary-normal distance. Zero on every band but the
  // outermost, so a specimen wears one aura rather than one per band.
  const glow = shape.glow || 0

  // The equation is normalised to rmax, so in the shape's own units the boundary
  // never leaves the unit circle. In pixels that is sx by sy, plus the outline.
  const reach = Math.max(sx, sy) + half + glow + 2
  const x0 = Math.max(0, Math.floor(cx - reach))
  const x1 = Math.min(surface.width - 1, Math.ceil(cx + reach))
  const y0 = Math.max(0, Math.floor(cy - reach))
  const y1 = Math.min(surface.height - 1, Math.ceil(cy + reach))

  // Normalised distance to pixels. The two axes disagree whenever the shape is
  // stretched; the shorter one is used so the antialiasing band is never
  // narrower than a pixel, which is the direction that would alias.
  const perUnit = Math.min(sx, sy)
  const outside = 1 + (half + glow + 1.5) / perUnit

  for (let py = y0; py <= y1; py++) {
    const dy0 = py + 0.5 - cy

    for (let px = x0; px <= x1; px++) {
      const dx0 = px + 0.5 - cx

      const x = (dx0 * cos - dy0 * sin) / sx
      const y = (dx0 * sin + dy0 * cos) / sy
      const rr = Math.sqrt(x * x + y * y)

      if (rr > outside) continue

      const [radius, cosine] = boundary(Math.atan2(y, x), m, n1, n2, n3)
      const edge = radius / rmax
      const distance = (rr - edge) * perUnit * cosine

      if (shape.fill_alpha > 0) {
        const coverage = Math.min(1, 0.5 - distance)

        if (coverage > 0) {
          // Gradient coordinate is the fraction of the way from the centre to
          // the boundary *along this ray*, not the fraction of the bounding
          // circle. On a spiky shape those are wildly different, and the
          // second one paints the arms in the core colour while the notches
          // between them get the rim colour — the shading would fight the
          // silhouette instead of following it.
          const q = span > 0
            ? Math.min(1, Math.max(0, (rr / (edge || 1e-6) - inner) / span))
            : 1

          // Biased hard towards the rim, so a band is mostly its own flat
          // colour with a narrow dark contact shadow where the band outside it
          // sits on top. A linear ramp instead makes the whole specimen one
          // continuous airbrushed glow: the band edges vanish and the stack
          // stops reading as stacked.
          const u = q * q * q

          surface.blend(
            px, py,
            fr + (er - fr) * u,
            fg + (eg - fg) * u,
            fb + (eb - fb) * u,
            coverage * shape.fill_alpha,
          )
        }
      }

      if (half > 0) {
        const coverage = Math.min(1, 0.5 - (Math.abs(distance) - half))
        if (coverage > 0) surface.blend(px, py, kr, kg, kb, coverage * shape.stroke_alpha)
      }

      // Outside the boundary only, so the silhouette itself stays crisp.
      //
      // Measured radially, deliberately skipping the `cosine` correction the
      // fill and the outline both need. That correction divides out the angle
      // between ray and normal, and along the flank of a spike it is near zero:
      // the halo would then reach hundreds of pixels out along every arm and
      // get chopped off square by the loop bound, which is a grey rectangle
      // around the shape. Radially it reaches exactly `glow` pixels, which is
      // what `outside` above is sized for.
      //
      // Squared falloff rather than linear: the eye finds the end of a
      // constant-slope gradient easily and the end of a tangent one barely at
      // all, so linear leaves a visible ring at the cutoff.
      if (glow > 0) {
        const radial = (rr - edge) * perUnit

        if (radial > 0 && radial < glow) {
          const falloff = 1 - radial / glow
          surface.blend(px, py, kr, kg, kb, falloff * falloff * GLOW_ALPHA)
        }
      }
    }
  }
}

export function render (ctx, spec) {
  const surface = new Surface(spec.width, spec.height)
  const [r, g, b] = hexToRgb(spec.background)
  surface.fill(r, g, b)

  // Painter's order, exactly as the server emitted it: outermost ring of each
  // cluster first, so the nesting reads from the outside in.
  for (const shape of spec.shapes) {
    drawShape(surface, shape)
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
