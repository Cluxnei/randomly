/**
 * Melody — docs/09 §4.
 *
 * The notes were chosen on the server, by whichever of the four methods the
 * panel asked for. Everything here is instrument: a frequency, an envelope drawn
 * inside musical bounds, and one of four synthesis engines.
 */
import { midiToHz, mix } from './dsp.js'
import { SampleRng, buffers, voice } from './synth.js'

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const [out] = buffers(1, length)
  const sampleRng = SampleRng.from(rng)

  for (const note of score.notes) {
    // The release happens *after* the note's written length, which is why the
    // buffer is longer than the note is. Rendering only the written length would
    // cut every note off at the moment it starts to release — an envelope with
    // an R that never sounds, and a melody that clicks on every note.
    const held = Math.round((note.length + score.envelope.release) * sampleRate)
    const at = Math.round(note.start * sampleRate)

    if (at >= length) break

    const rendered = voice(midiToHz(note.midi), held, {
      engine: score.engine,
      timbre: score.timbre,
      envelope: score.envelope,
      sampleRate,
      rng: sampleRng,
    })

    mix(out, rendered, at, note.velocity)
  }

  return [out]
}
