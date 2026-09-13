/**
 * Karplus-Strong — docs/09 §6.
 *
 * A delay line of fs/f samples, filled with white noise and fed back through a
 * two-point average. Nothing in it models a string, and a string comes out. The
 * delay length selects the pitch, because only the frequencies whose period
 * divides the loop survive a lap; the averaging is a one-pole low pass, so each
 * lap loses a little more of the top, which is what a real string does as its
 * higher partials radiate away first.
 *
 * The synthesis is in dsp.js, including the note about which neighbouring sample
 * to average with — get that wrong and the string brightens on every lap instead
 * of darkening. It still sounds like something, which is exactly why it is
 * measured.
 */
import { biquad, karplusStrong, lowpass, midiToHz } from './dsp.js'
import { SampleRng, buffers, pan } from './synth.js'

/** No note rings longer than this, whatever the arithmetic says. */
const MAX_RING = 8

/**
 * How long to render a note before it is inaudible.
 *
 * Amplitude is multiplied by `decay` once per lap and there are f laps a second,
 * so it falls as decay^(f·t). Solving for −60 dB gives the time below. Rendering
 * every note for a fixed length instead would either cut the low notes off — they
 * lap fewer times a second and so ring longer — or spend most of the render
 * multiplying silence.
 */
function ringSeconds (frequency, decay) {
  if (decay >= 1) return MAX_RING

  return Math.min(MAX_RING, Math.log(0.001) / (frequency * Math.log(decay)))
}

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const channels = buffers(2, length)
  const sampleRng = SampleRng.from(rng)

  /*
   * Brightness as a low pass on the output rather than on the excitation.
   *
   * Plucking position is physically a comb filter on the initial burst, but the
   * burst lives inside dsp.js `karplusStrong` and belongs there — the primitive
   * owning its own noise is what makes it a primitive. A low pass across the
   * note is the same first-order effect on the part anyone can hear: bright and
   * thin near the bridge, round and dark over the fretboard.
   */
  const colour = lowpass(400 * (2 ** (score.brightness * 5.3)), 0.707, sampleRate)

  for (const note of score.notes) {
    const at = Math.round(note.start * sampleRate)

    if (at >= length) continue

    const frequency = midiToHz(note.midi)
    const ring = Math.min(
      length - at,
      Math.round(ringSeconds(frequency, note.decay) * sampleRate),
    )

    if (ring < 2) continue

    const string = biquad(
      karplusStrong(ring, frequency, sampleRng, { decay: note.decay, sampleRate }),
      colour,
    )

    // One millisecond of fade-in. The delay line starts full of noise at full
    // amplitude, so sample zero is a step from silence — a click in front of
    // every note, which is audible even though the pluck transient is meant to
    // be sharp.
    const attack = Math.min(ring, Math.round(0.001 * sampleRate))
    for (let i = 0; i < attack; i++) string[i] *= i / attack

    // And a fade at the other end, for the same reason: a note cut off at MAX_RING
    // or at the end of the piece stops on a non-zero sample, and a step is a click.
    const release = Math.min(ring, Math.round(0.01 * sampleRate))
    for (let i = 0; i < release; i++) string[ring - 1 - i] *= i / release

    pan(channels, string, at, note.pan, note.velocity)
  }

  return channels
}
