/**
 * Chord progressions — docs/09 §8.
 *
 * The harmony is the server's: which chords, in which order, voiced how, and the
 * cadence that ends it. This half is the instrument, and there are four of them
 * because the same progression through a pad and through an electric piano are
 * two different pieces of music.
 */
import { midiToHz, mix } from './dsp.js'
import { SampleRng, buffers, pan, voice } from './synth.js'

/**
 * Four instruments as four sets of numbers.
 *
 * The two that matter are attack and unison. A slow attack turns a chord from an
 * event into a wash — nothing is struck, it simply arrives — and detuned copies
 * beat slowly against each other, which is the difference between a synthesiser
 * playing three notes and a section playing them.
 */
const INSTRUMENTS = {
  pad: {
    engine: 'additive',
    timbre: { partials: [1, 0.5, 0.35, 0.2, 0.12, 0.08, 0.05] },
    envelope: { attack: 0.35, decay: 0.6, sustain: 0.65, release: 1.4 },
    unison: 2,
    detune: 11,
  },
  piano: {
    engine: 'fm',
    // Ratio 2 with a decaying index is the Rhodes sound: a struck tine, bright
    // at the moment of the strike and mellowing within a tenth of a second.
    timbre: { ratio: 2, index: 4.5 },
    envelope: { attack: 0.004, decay: 0.5, sustain: 0.22, release: 0.9 },
    unison: 1,
    detune: 0,
  },
  organ: {
    // Drawbar-ish: the octave and the twelfth loud, the rest filling in. An
    // organ's partials do not fall off smoothly, which is why it does not sound
    // like a saw wave through a filter.
    engine: 'additive',
    timbre: { partials: [1, 0.62, 0.8, 0.3, 0.5, 0.18, 0.24, 0.12] },
    envelope: { attack: 0.02, decay: 0.05, sustain: 0.9, release: 0.12 },
    unison: 1,
    detune: 0,
  },
  strings: {
    engine: 'subtractive',
    timbre: { wave: 'saw', cutoff: 2200, q: 0.9 },
    envelope: { attack: 0.25, decay: 0.5, sustain: 0.7, release: 1.1 },
    unison: 3,
    detune: 14,
  },
}

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const channels = buffers(2, length)
  const sampleRng = SampleRng.from(rng)
  const instrument = INSTRUMENTS[score.instrument] ?? INSTRUMENTS.pad

  for (const chord of score.chords) {
    const at = Math.round(chord.start * sampleRate)

    if (at >= length) continue

    const held = Math.min(
      length - at,
      Math.round((chord.length + instrument.envelope.release) * sampleRate),
    )

    chord.midi.forEach((midi, index) => {
      const rendered = voice(midiToHz(midi), held, {
        ...instrument,
        envelope: instrument.envelope,
        sampleRate,
        rng: sampleRng,
      })

      // The voices are spread across the image from left to right in the order
      // they are stacked, which is how a keyboard is laid out and how a section
      // is seated. A chord panned to one point is a chord heard as one note.
      const spread = chord.midi.length === 1 ? 0 : (index / (chord.midi.length - 1)) * 0.8 - 0.4

      pan(channels, rendered, at, spread, chord.velocity / chord.midi.length)
    })

    if (chord.bass === null || chord.bass === undefined) continue

    // Bass stays centred and stays a sine. Low frequencies carry no directional
    // information anyway, and anything richer than a sine down there turns into
    // mud the moment the chord above it moves.
    const bass = voice(midiToHz(chord.bass), held, {
      engine: 'sine',
      envelope: { attack: 0.02, decay: 0.3, sustain: 0.55, release: 0.5 },
      sampleRate,
      rng: sampleRng,
    })

    mix(channels[0], bass, at, chord.velocity * 0.45)
    mix(channels[1], bass, at, chord.velocity * 0.45)
  }

  return channels
}
