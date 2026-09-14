/**
 * Drone — docs/09 §7.
 *
 * The whole instrument is pairs of sines. Each partial is two of them a fraction
 * of a hertz apart, and what the listener hears is not either one: it is their
 * sum swelling and fading at the difference between them. Nothing modulates the
 * amplitude here — there is no LFO on a gain — the pulse is what two close
 * frequencies do when you add them together.
 *
 * Written as a phasor per sine rather than Math.sin per sample. A ninety-second
 * render at 44.1 kHz is four million samples, and ten partials is twenty sines
 * across all of them: eighty million sine calls against eighty million multiply
 * pairs. The coupled rotation below also keeps its magnitude under rounding,
 * which the naive sin(ω·i) does not once the argument passes a few hundred
 * thousand radians.
 */
import { curveAt, sweptLowpass } from './dsp.js'
import { buffers, pan } from './synth.js'

/** One sine, written into `out` with a slowly moving level. */
function addSine (out, frequency, sampleRate, phase, amplitude, levels) {
  const w = 2 * Math.PI * frequency / sampleRate
  const c = Math.cos(w)
  const s = Math.sin(w)

  let re = Math.cos(2 * Math.PI * phase)
  let im = Math.sin(2 * Math.PI * phase)

  for (let i = 0; i < out.length; i++) {
    out[i] += im * amplitude * curveAt(levels, i / out.length)

    const next = re * c - im * s
    im = re * s + im * c
    re = next
  }
}

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const channels = buffers(2, length)

  for (const partial of score.partials) {
    const voice = new Float32Array(length)

    // The pair. Equal amplitudes, because beating is only total — a full swell
    // down to silence — when the two sines cancel exactly. Unequal levels leave
    // a residue and the effect turns into a wobble.
    addSine(voice, partial.frequency, sampleRate, partial.phase, partial.amplitude, partial.levels)
    addSine(voice, partial.partner, sampleRate, partial.phase + 0.37, partial.amplitude, partial.levels)

    pan(channels, voice, 0, partial.pan, 1)
  }

  // One swept low pass per channel, over the sum rather than over each partial:
  // a biquad has state, and running twenty of them costs twenty times what
  // running two does for a difference nobody could hear.
  for (let c = 0; c < channels.length; c++) {
    channels[c] = sweptLowpass(channels[c], score.filter, sampleRate)
  }

  /*
   * A long fade at each end.
   *
   * Equal-power rather than linear: two uncorrelated partials fading together
   * lose 3 dB in the middle of a linear fade, which is audible as a dip on the
   * way in. cos²/sin² keeps the level constant through the crossing.
   */
  const fade = Math.min(Math.floor(length / 2), Math.round(score.fade * sampleRate))

  for (let i = 0; i < fade; i++) {
    const shape = Math.sin(Math.PI * 0.5 * i / fade) ** 2

    for (const channel of channels) {
      channel[i] *= shape
      channel[length - 1 - i] *= shape
    }
  }

  return channels
}
