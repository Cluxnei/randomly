/**
 * RBJ cookbook biquad coefficients, and the one filter chain that is an opinion
 * rather than a formula.
 *
 * dsp.js ships `lowpass` because that is all the primitives needed. Everything
 * here is the same derivation at other shapes, kept apart from the primitives so
 * that file stays the short list of things every generator uses.
 *
 * Every design returns coefficients already divided through by a0, which is the
 * form dsp.js `biquad` expects.
 */

/** Shared intermediates: the normalised angular frequency and the bandwidth term. */
function common (frequency, q, sampleRate) {
  // Clamped below Nyquist. A cutoff dragged past it makes cos(w) fold back and
  // the filter silently becomes a different filter — stable, wrong, inaudible
  // as a bug until you measure the slope.
  const f = Math.max(10, Math.min(frequency, sampleRate * 0.45))
  const w = 2 * Math.PI * f / sampleRate

  return { w, cos: Math.cos(w), alpha: Math.sin(w) / (2 * Math.max(0.05, q)) }
}

function normalise (b0, b1, b2, a0, a1, a2) {
  return { b0: b0 / a0, b1: b1 / a0, b2: b2 / a0, a1: a1 / a0, a2: a2 / a0 }
}

export function highpass (frequency, q, sampleRate) {
  const { cos, alpha } = common(frequency, q, sampleRate)

  return normalise(
    (1 + cos) / 2, -(1 + cos), (1 + cos) / 2,
    1 + alpha, -2 * cos, 1 - alpha,
  )
}

/** Constant 0 dB peak gain, so raising Q narrows the window without raising the level. */
export function bandpass (frequency, q, sampleRate) {
  const { cos, alpha } = common(frequency, q, sampleRate)

  return normalise(alpha, 0, -alpha, 1 + alpha, -2 * cos, 1 - alpha)
}

export function peaking (frequency, q, gainDb, sampleRate) {
  const { cos, alpha } = common(frequency, q, sampleRate)
  // A is a *amplitude* ratio at the peak, hence /40 rather than /20: the shelf
  // and peaking designs apply A on both sides of the transfer function.
  const A = 10 ** (gainDb / 40)

  return normalise(
    1 + alpha * A, -2 * cos, 1 - alpha * A,
    1 + alpha / A, -2 * cos, 1 - alpha / A,
  )
}

export function lowShelf (frequency, q, gainDb, sampleRate) {
  const { cos, alpha } = common(frequency, q, sampleRate)
  const A = 10 ** (gainDb / 40)
  const beta = 2 * Math.sqrt(A) * alpha

  return normalise(
    A * ((A + 1) - (A - 1) * cos + beta),
    2 * A * ((A - 1) - (A + 1) * cos),
    A * ((A + 1) - (A - 1) * cos - beta),
    (A + 1) + (A - 1) * cos + beta,
    -2 * ((A - 1) + (A + 1) * cos),
    (A + 1) + (A - 1) * cos - beta,
  )
}

export function highShelf (frequency, q, gainDb, sampleRate) {
  const { cos, alpha } = common(frequency, q, sampleRate)
  const A = 10 ** (gainDb / 40)
  const beta = 2 * Math.sqrt(A) * alpha

  return normalise(
    A * ((A + 1) + (A - 1) * cos + beta),
    -2 * A * ((A - 1) + (A + 1) * cos),
    A * ((A + 1) + (A - 1) * cos - beta),
    (A + 1) - (A - 1) * cos + beta,
    2 * ((A - 1) - (A + 1) * cos),
    (A + 1) - (A - 1) * cos - beta,
  )
}

/**
 * The inverse equal-loudness curve that makes grey noise — docs/09 §2.
 *
 * Grey is the only colour on the list defined by perception rather than by
 * arithmetic. The ear is most sensitive around 3–4 kHz and increasingly deaf
 * below 200 Hz and above 10 kHz, so a signal that *sounds* equally loud in every
 * band has to carry far more energy where the ear is weak and less where it is
 * strong. These four stages trace the inverse of the ISO 226 40-phon contour:
 *
 *   +16 dB shelf under 180 Hz and again under 50 Hz — the contour rises about
 *          35 dB between 200 Hz and 20 Hz, and one shelf cannot be that steep
 *   −11 dB bell at 3.4 kHz — the ear canal resonance, the most sensitive point
 *    +9 dB shelf over 10 kHz — where sensitivity falls away again
 *
 * The result measures as a strongly tilted spectrum and is heard as flatter than
 * white, which is the entire point and the reason the spectrum test treats this
 * colour differently from the other five.
 */
export function greyChain (sampleRate) {
  return [
    lowShelf(180, 0.7, 16, sampleRate),
    lowShelf(50, 0.7, 16, sampleRate),
    peaking(3400, 1.1, -11, sampleRate),
    highShelf(10000, 0.7, 9, sampleRate),
  ]
}
