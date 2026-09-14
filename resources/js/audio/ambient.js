/**
 * Generative ambient — docs/09 §7.
 *
 * Three layers, and the piece is what happens between them: a noise bed under a
 * slowly moving filter, pads that overlap so one chord is always arriving while
 * another leaves, and single notes — motes — falling at irregular intervals on
 * top. None of the three is interesting alone. Together they are the Eno trick:
 * several slow things whose cycles do not divide each other, so the combination
 * takes far longer to repeat than any part of it.
 *
 * The lengths here are what stops that being a problem for the studio. A piece
 * that runs forever cannot be rendered synchronously, so the server bounds it and
 * the player loops the buffer — and because the fades at each end are equal
 * power, the loop point is inaudible.
 */
import { brownNoise, midiToHz, mix, pinkNoise, sweptLowpass } from './dsp.js'
import { SampleRng, buffers, pan, voice } from './synth.js'

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const channels = buffers(2, length)
  const sampleRng = SampleRng.from(rng)

  bed(channels, score, sampleRate, sampleRng)
  pads(channels, score, sampleRate, sampleRng)
  motes(channels, score, sampleRate, sampleRng)
  fade(channels, score, sampleRate)

  return channels
}

/**
 * The noise floor, filtered and drifting.
 *
 * Rendered once in mono and mixed to both channels at slightly different levels
 * rather than generated twice. Two independent noise sources would be genuinely
 * wider, and would also be two draws of a Voss-McCartney ladder across a minute
 * of samples, which is the most expensive thing in this file. The small level
 * difference gives the bed a side without the second pass.
 */
function bed (channels, score, sampleRate, rng) {
  if (score.bed.level <= 0) return

  const length = channels[0].length
  const raw = score.bed.colour === 'brown'
    ? brownNoise(length, rng)
    : pinkNoise(length, rng)

  const filtered = sweptLowpass(raw, score.bed.filter, sampleRate, 0.05)

  mix(channels[0], filtered, 0, score.bed.level)
  mix(channels[1], filtered, 0, score.bed.level * 0.86)
}

/**
 * Overlapping chords.
 *
 * Each pad is rendered for its written length plus its release, which is why the
 * starts in the score are closer together than the lengths: at any moment two
 * chords are usually sounding, one arriving and one leaving. Cutting each pad at
 * its written end instead would give a sequence of separate chords, which is a
 * progression rather than a wash.
 */
function pads (channels, score, sampleRate, rng) {
  const length = channels[0].length

  for (const pad of score.pads) {
    const at = Math.round(pad.start * sampleRate)

    if (at >= length) continue

    const held = Math.min(
      length - at,
      Math.round((pad.length + score.pad.envelope.release) * sampleRate),
    )

    if (held < 2) continue

    pad.midi.forEach((midi, index) => {
      const rendered = voice(midiToHz(midi), held, {
        engine: 'additive',
        timbre: { partials: score.pad.partials },
        envelope: score.pad.envelope,
        sampleRate,
        rng,
        unison: score.pad.unison,
        detune: score.pad.detune,
      })

      // The voices of one chord are spread across the image in the order they
      // are stacked, the same way a chord generator's are — a chord panned to
      // one point is a chord heard as a single note.
      const spread = pad.midi.length === 1
        ? 0
        : ((index / (pad.midi.length - 1)) * 2 - 1) * pad.spread

      pan(channels, rendered, at, spread, pad.velocity / pad.midi.length)
    })
  }
}

/** Single struck notes — an FM bell, panned wherever the score put it. */
function motes (channels, score, sampleRate, rng) {
  const length = channels[0].length

  for (const mote of score.motes) {
    const at = Math.round(mote.start * sampleRate)

    if (at >= length) continue

    const held = Math.min(length - at, Math.round((mote.length + score.mote.envelope.release) * sampleRate))

    if (held < 2) continue

    const rendered = voice(midiToHz(mote.midi), held, {
      engine: 'fm',
      timbre: { ratio: mote.ratio, index: mote.index },
      envelope: score.mote.envelope,
      sampleRate,
      rng,
    })

    pan(channels, rendered, at, mote.pan, mote.velocity)
  }
}

/**
 * Equal-power fades at both ends, so the buffer can be looped.
 *
 * sin² in and out sums to a constant through the crossing; a linear pair would
 * dip 3 dB at the seam, which is audible as a breath every time the piece comes
 * round — the one artefact that would give away that this is a loop rather than
 * a piece that never repeats.
 */
function fade (channels, score, sampleRate) {
  const length = channels[0].length
  const span = Math.min(Math.floor(length / 2), Math.round(score.fade * sampleRate))

  for (let i = 0; i < span; i++) {
    const shape = Math.sin(Math.PI * 0.5 * i / span) ** 2

    for (const channel of channels) {
      channel[i] *= shape
      channel[length - 1 - i] *= shape
    }
  }
}
