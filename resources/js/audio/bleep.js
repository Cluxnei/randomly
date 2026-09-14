/**
 * UI sounds — docs/09 §7.
 *
 * A pack rather than a piece: several short sounds laid out along one buffer with
 * silence between them, which is what makes the WAV export useful. One download
 * holds the whole set, and the meta lists the offset and length of each sound so
 * they can be cut apart — the alternative, a zip of eight files, would need a
 * server to build and could not be played in the page before downloading.
 *
 * The synthesis is deliberately plain: swept oscillators, one FM voice for the
 * bell, and a filtered noise burst. Interface sounds have to survive a phone
 * speaker at arm's length, and everything subtle about them is lost there. What
 * survives is pitch direction — up for success, down for error — and length.
 */
import { biquad, midiToHz } from './dsp.js'
import { bandpass } from './filters.js'
import { SampleRng, buffers, sweptOscillator } from './synth.js'

/**
 * Attack-then-decay, with the decay exponential.
 *
 * Not the dsp.js ADSR, which is linear in all four segments. A linear decay
 * sounds like a fader being pulled; anything struck, plucked or clicked decays
 * exponentially, and over the 60 ms of a coin sound that difference is most of
 * what makes it read as a sound rather than a beep.
 *
 * The last two milliseconds are faded to true zero. An exponential never reaches
 * it, so the buffer would otherwise end on a step — broadband, audible as a
 * click, and the exact bug the drum kit had until the onset detector found it.
 */
function shape (length, attackSamples, curve) {
  const out = new Float32Array(length)
  const attack = Math.max(1, Math.min(length, attackSamples))
  const tail = Math.min(length, Math.max(1, Math.round(length * 0.03)))

  for (let i = 0; i < length; i++) {
    const rise = i < attack ? i / attack : 1
    out[i] = rise * Math.exp(-curve * i / length)
  }

  for (let i = 0; i < tail; i++) out[length - 1 - i] *= i / tail

  return out
}

/** One voice of one sound: a swept oscillator, an FM partial, or a noise burst. */
function render1 (voice, sampleRate, rng) {
  const length = Math.max(2, Math.round(voice.length * sampleRate))
  const attack = Math.round(voice.attack * sampleRate)
  const envelope = shape(length, attack, voice.curve)
  const out = new Float32Array(length)

  if (voice.wave === 'noise') {
    const noise = new Float32Array(length)
    for (let i = 0; i < length; i++) noise[i] = rng.float() * 2 - 1

    const band = biquad(noise, bandpass(midiToHz(voice.midi), voice.q ?? 1.2, sampleRate))
    for (let i = 0; i < length; i++) out[i] = band[i] * envelope[i] * voice.level

    return out
  }

  if (voice.wave === 'fm') {
    // A bell: an inharmonic ratio and a modulation index that decays faster than
    // the note does. A constant index is a buzz; a struck object is bright at the
    // instant of the strike and loses its upper partials first.
    const carrier = 2 * Math.PI * midiToHz(voice.midi) / sampleRate
    const modulator = carrier * voice.ratio

    for (let i = 0; i < length; i++) {
      const index = voice.index * Math.exp(-4 * i / length)
      out[i] = Math.sin(carrier * i + index * Math.sin(modulator * i)) * envelope[i] * voice.level
    }

    return out
  }

  const partial = sweptOscillator(
    voice.wave,
    midiToHz(voice.midi),
    midiToHz(voice.midi + (voice.glide ?? 0)),
    length,
    sampleRate,
  )

  for (let i = 0; i < length; i++) out[i] = partial[i] * envelope[i] * voice.level

  return out
}

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const [out] = buffers(1, length)
  const sampleRng = SampleRng.from(rng)

  for (const sound of score.sounds) {
    for (const voice of sound.voices) {
      const at = Math.round((sound.start + voice.start) * sampleRate)

      if (at >= length) continue

      const rendered = render1(voice, sampleRate, sampleRng)
      const end = Math.min(length, at + rendered.length)

      for (let i = at; i < end; i++) out[i] += rendered[i - at]
    }
  }

  return [out]
}
