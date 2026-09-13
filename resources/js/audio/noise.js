/**
 * The noise colours — docs/09 §2.
 *
 * Six spectra, and the only thing separating them is how much energy sits at the
 * top against the bottom. The mapping is not the obvious one, and the reason is
 * written out at length in the `differentiate` docblock in dsp.js: differentiating
 * is +6 dB/octave per application, not +3, so violet is differentiated white and
 * blue is differentiated *pink*. Getting that backwards is completely inaudible,
 * which is why tests/Unit/AudioSpectrumTest.php measures every one of them.
 */
import { biquad, brownNoise, differentiate, lowpass, normalise, pinkNoise, whiteNoise } from './dsp.js'
import { bandpass, greyChain, highpass } from './filters.js'
import { SampleRng, buffers } from './synth.js'

/**
 * How much extra is rendered past the end to crossfade the loop with.
 *
 * 120 ms. Long enough that the fade is inaudible against noise, short enough
 * that the loop point is still where the score says it is.
 */
const LOOP_TAIL = 0.12

function source (colour, length, rng, sampleRate) {
  switch (colour) {
    case 'white': return whiteNoise(length, rng)
    case 'brown': return brownNoise(length, rng)
    // −3 + 6 = +3. Differentiating *white* here would give +6, which is violet,
    // and would sound like a slightly brighter hiss rather than like a bug.
    case 'blue': return differentiate(pinkNoise(length, rng), 1)
    case 'violet': return differentiate(whiteNoise(length, rng), 1)
    case 'grey': return grey(length, rng, sampleRate)
    default: return pinkNoise(length, rng)
  }
}

/** White bent through the inverse of the ear's own response — see filters.js. */
function grey (length, rng, sampleRate) {
  let signal = whiteNoise(length, rng)

  for (const coefficients of greyChain(sampleRate)) {
    signal = biquad(signal, coefficients)
  }

  // The shelves add something like 16 dB of bass, so the chain's output is far
  // past full scale before anything else has touched it.
  return normalise(signal, 0.9)
}

/**
 * How often the sweeping filter recomputes its coefficients.
 *
 * Every 128 samples — about 3 ms, well under any drift rate the sweep can
 * produce, and a 128th of the cost of designing a biquad per sample. Updating the
 * coefficients while keeping the state is the standard way to sweep a biquad;
 * the small discontinuity it leaves is inaudible at this rate and at these
 * modulation depths.
 */
const COEFFICIENT_BLOCK = 128

/**
 * The filter, drifting.
 *
 * The movement control is what turns noise into weather. A static low pass over
 * pink noise is a hiss with the top taken off; the same filter wandering an
 * octave over ten seconds is surf, or wind, or rain against a window — the
 * difference between a test tone and something a person will leave playing.
 *
 * The drift is interpolated over breakpoints the *server* drew, wrapping back to
 * the first one at the end, so a looped buffer has no discontinuity at the seam.
 */
function sweep (signal, filter, sampleRate) {
  const { type, cutoff, q, movement, sweep: points } = filter

  if (type === 'none' || !points || points.length === 0) return signal

  const design = type === 'highpass' ? highpass : type === 'bandpass' ? bandpass : lowpass
  const out = new Float32Array(signal.length)

  let z1 = 0
  let z2 = 0
  let coefficients = null

  for (let i = 0; i < signal.length; i++) {
    if (i % COEFFICIENT_BLOCK === 0) {
      const position = (i / signal.length) * points.length
      const index = Math.floor(position)
      const fraction = position - index

      // Cosine interpolation between breakpoints: linear interpolation has a
      // corner at every breakpoint, and a corner in a filter sweep is audible as
      // a tick even when the sweep itself is slow.
      const a = points[index % points.length]
      const b = points[(index + 1) % points.length]
      const smooth = (1 - Math.cos(fraction * Math.PI)) / 2
      const multiplier = a + (b - a) * smooth

      // Applied as an exponent so `movement` is measured in octaves and a
      // movement of zero is exactly a multiplier of one.
      coefficients = design(cutoff * (multiplier ** movement), q, sampleRate)
    }

    const x = signal[i]
    const y = coefficients.b0 * x + z1
    z1 = coefficients.b1 * x - coefficients.a1 * y + z2
    z2 = coefficients.b2 * x - coefficients.a2 * y
    out[i] = y
  }

  return out
}

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const tail = score.loop ? Math.round(LOOP_TAIL * sampleRate) : 0

  /*
   * Normalised *after* the filter, not before.
   *
   * The colours arrive at wildly different crest factors and the filter then
   * throws away however much of the spectrum it was pointed at — a band pass at
   * 1.2 kHz over grey noise, whose energy is nearly all under 200 Hz, measured at
   * a peak of 0.02 when the level was normalised first. That is a control that
   * silences the generator, which reads as a bug however defensible the
   * arithmetic. Levelling last means every colour and every filter setting comes
   * out at the same height, and the Volume control means what it says.
   */
  const noise = normalise(
    sweep(
      source(score.colour, length + tail, SampleRng.from(rng), sampleRate),
      score.filter,
      sampleRate,
    ),
    0.9,
  )

  const [out] = buffers(1, length)
  out.set(noise.subarray(0, length))

  if (score.loop) {
    /*
     * Crossfade the extra tail onto the head.
     *
     * A hard loop of noise clicks, because the last sample and the first are
     * unrelated and the join is a step. Equal-power weights (the square roots)
     * rather than linear ones: two uncorrelated noise signals summed at 0.5 each
     * are 3 dB quieter than either, so a linear crossfade leaves an audible dip
     * exactly at the seam — which is the one moment a listener is listening for.
     */
    for (let i = 0; i < tail; i++) {
      const t = i / tail
      out[i] = out[i] * Math.sqrt(t) + noise[length + i] * Math.sqrt(1 - t)
    }
  } else {
    const fade = Math.max(1, Math.round((score.fade ?? 0.02) * sampleRate))

    for (let i = 0; i < fade && i < length; i++) {
      out[i] *= i / fade
      out[length - 1 - i] *= i / fade
    }
  }

  return [out]
}
