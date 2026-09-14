/**
 * Voices: the things the scores ask for that dsp.js does not already provide.
 *
 * dsp.js owns the primitives every generator shares — envelopes, noise colours,
 * the delay line, the limiter. This file owns the instruments: oscillators, the
 * FM and additive engines, and the drum kits. Same rule applies to both, and it
 * is the rule the whole module is built on: plain JavaScript writing into a
 * Float32Array, never a Web Audio node, so the identical code can be run under
 * Node and measured.
 */
import { adsr, biquad, lowpass, mix } from './dsp.js'
import { bandpass, highpass } from './filters.js'

/**
 * A fast PRNG for per-sample noise, seeded from the real stream.
 *
 * The Rng in rng.js expands HKDF, and HKDF-Expand is a key derivation function,
 * not a stream cipher: every 32 bytes costs an HMAC-SHA256 and `float()` spends
 * seven of them. That is 300 KB of key material and ~10,000 HMAC blocks for one
 * second of white noise, measured at roughly half a second per megabyte in both
 * Node and the browser — a thirty-second render would sit there deriving key
 * material for five seconds before making a sound.
 *
 * So the HKDF stream is used for what it is good at: it seeds this. The chain
 * stays unbroken and fully deterministic — beacon entropy → seed → render key →
 * HKDF → these 16 bytes → every sample — and nothing about the claim changes,
 * because unlike the canvas renderers there is no PHP implementation of the
 * samples for these bytes to have to match.
 *
 * xoshiro128** rather than a linear congruential generator: an LCG's low bits
 * are famously non-random, which in audio means a periodic buzz sitting under
 * the noise, and the spectrum test would find it.
 */
export class SampleRng {
  constructor (state) {
    this.s = state
  }

  /** Draw 16 bytes from the real stream and refuse the all-zero state. */
  static from (rng) {
    const bytes = rng.read(16)
    const state = new Uint32Array(4)

    for (let i = 0; i < 4; i++) {
      state[i] = (bytes[i * 4] << 24 | bytes[i * 4 + 1] << 16 | bytes[i * 4 + 2] << 8 | bytes[i * 4 + 3]) >>> 0
    }

    // All zeroes is xoshiro's one fixed point: it would stay there forever and
    // the generator would output digital silence. Astronomically unlikely and
    // trivially guarded.
    if ((state[0] | state[1] | state[2] | state[3]) === 0) state[0] = 0x9e3779b9

    return new SampleRng(state)
  }

  next () {
    const s = this.s
    const result = (Math.imul(rotl(Math.imul(s[1], 5) >>> 0, 7), 9)) >>> 0
    const t = (s[1] << 9) >>> 0

    s[2] ^= s[0]
    s[3] ^= s[1]
    s[1] ^= s[2]
    s[0] ^= s[3]
    s[2] ^= t
    s[3] = rotl(s[3], 11)

    return result
  }

  /**
   * A uniform float in [0, 1).
   *
   * 32 bits, not 53. The output is quantised to 16 bits in the WAV and rather
   * less than that by any speaker, so the missing mantissa is some 80 dB below
   * anything audible.
   */
  float () {
    return this.next() * 2.3283064365386963e-10
  }
}

function rotl (x, k) {
  return ((x << k) | (x >>> (32 - k))) >>> 0
}

/**
 * PolyBLEP: the correction that stops a saw sounding like a bell.
 *
 * A naive saw or square is a step discontinuity once per cycle, and a step has
 * infinite bandwidth — everything above Nyquist folds back down as inharmonic
 * partials that do not move with the note. The result is a synth that is
 * gratingly out of tune in the top octave and fine in the bottom one. Subtracting
 * a polynomial approximation of a band-limited step around each discontinuity
 * removes most of it for a couple of multiplies.
 */
function polyblep (t, dt) {
  if (t < dt) {
    const x = t / dt
    return x + x - x * x - 1
  }

  if (t > 1 - dt) {
    const x = (t - 1) / dt
    return x * x + x + x + 1
  }

  return 0
}

/** One cycle-accurate oscillator. `phase` is in turns, so 0.25 is a quarter cycle. */
export function oscillator (wave, frequency, length, sampleRate, phase = 0) {
  const out = new Float32Array(length)
  const dt = frequency / sampleRate
  let t = phase - Math.floor(phase)

  for (let i = 0; i < length; i++) {
    switch (wave) {
      case 'saw':
        out[i] = 2 * t - 1 - polyblep(t, dt)
        break
      case 'square':
        out[i] = (t < 0.5 ? 1 : -1) + polyblep(t, dt) - polyblep((t + 0.5) % 1, dt)
        break
      // Triangle's partials fall as 1/k² rather than 1/k, so its aliasing sits
      // some 30 dB lower than a saw's and does not need correcting.
      case 'triangle':
        out[i] = 4 * Math.abs(t - 0.5) - 1
        break
      default:
        out[i] = Math.sin(2 * Math.PI * t)
    }

    t += dt
    if (t >= 1) t -= 1
  }

  return out
}

/**
 * An oscillator whose pitch glides, for the sounds that are a slide rather than
 * a note — a coin flick, an error buzz, the swoop under a notification.
 *
 * Separate from `oscillator` above rather than a flag on it because the two have
 * genuinely different inner loops: a fixed oscillator knows its phase increment
 * once, and this one recomputes it every sample. Sharing the code would put a
 * branch in the hottest loop in the module for the benefit of neither.
 *
 * The glide is exponential, which is to say linear in pitch. A linear sweep in
 * hertz from 400 to 1600 spends half its time in the top octave and sounds like
 * a laser; the same sweep in cents sounds like something rising.
 */
export function sweptOscillator (wave, from, to, length, sampleRate, phase = 0) {
  const out = new Float32Array(length)
  let t = phase - Math.floor(phase)

  for (let i = 0; i < length; i++) {
    const frequency = from * ((to / from) ** (length === 1 ? 0 : i / (length - 1)))
    const dt = frequency / sampleRate

    switch (wave) {
      case 'saw':
        out[i] = 2 * t - 1 - polyblep(t, dt)
        break
      case 'square':
        out[i] = (t < 0.5 ? 1 : -1) + polyblep(t, dt) - polyblep((t + 0.5) % 1, dt)
        break
      case 'triangle':
        out[i] = 4 * Math.abs(t - 0.5) - 1
        break
      default:
        out[i] = Math.sin(2 * Math.PI * t)
    }

    t += dt
    if (t >= 1) t -= 1
  }

  return out
}

/**
 * FM: y(t) = sin(2π f_c t + I(t)·sin(2π f_m t)) — docs/09 §6.
 *
 * The modulation index gets its own decaying envelope, which is the whole
 * difference between "an FM tone" and "a bell". A struck object is bright at the
 * instant of the strike and loses its upper partials fastest; a constant index
 * gives a static buzz that no physical object makes.
 */
function fm (frequency, length, sampleRate, { ratio = 2, index = 3 }) {
  const out = new Float32Array(length)
  const carrier = 2 * Math.PI * frequency / sampleRate
  const modulator = carrier * ratio

  for (let i = 0; i < length; i++) {
    const decay = Math.exp(-3 * i / length)
    out[i] = Math.sin(carrier * i + index * decay * Math.sin(modulator * i))
  }

  return out
}

/**
 * Additive: Σ aₖ·sin(2π k f t) — docs/09 §6.
 *
 * Partials above Nyquist are skipped rather than summed. Folding them back is
 * the same aliasing problem as the naive saw, and here it is avoidable exactly:
 * the series is built one harmonic at a time, so we simply know when to stop.
 */
function additive (frequency, length, sampleRate, { partials = [1, 0.5, 0.33, 0.25] }) {
  const out = new Float32Array(length)

  for (let k = 0; k < partials.length; k++) {
    const f = frequency * (k + 1)
    if (f >= sampleRate / 2) break

    const w = 2 * Math.PI * f / sampleRate
    const amplitude = partials[k]

    /*
     * A rotating phasor rather than Math.sin per sample.
     *
     * Eight partials across a three-second pad is a million sines a note, and a
     * chord is a dozen notes — measured at a second of render for one default
     * progression, most of it here. Two multiplies and an add give the same
     * value. The coupled form below also keeps its magnitude under rounding,
     * which the naive `sin(w·i)` does not once `w·i` grows past a few hundred
     * thousand radians and the argument reduction starts losing bits.
     */
    const c = Math.cos(w)
    const s = Math.sin(w)
    let re = 1
    let im = 0

    for (let i = 0; i < length; i++) {
      out[i] += amplitude * im
      const next = re * c - im * s
      im = re * s + im * c
      re = next
    }
  }

  return out
}

/** Oscillator into a resonant low pass — the classic subtractive chain, docs/09 §6. */
function subtractive (frequency, length, sampleRate, { wave = 'saw', cutoff = 1800, q = 1.2 }) {
  return biquad(oscillator(wave, frequency, length, sampleRate), lowpass(cutoff, q, sampleRate))
}

function tone (engine, frequency, length, sampleRate, timbre) {
  switch (engine) {
    case 'fm': return fm(frequency, length, sampleRate, timbre)
    case 'additive': return additive(frequency, length, sampleRate, timbre)
    case 'subtractive': return subtractive(frequency, length, sampleRate, timbre)
    default: return oscillator('sine', frequency, length, sampleRate)
  }
}

/**
 * One pitched note, envelope and all.
 *
 * `unison` stacks detuned copies. Two saws a few cents apart beat slowly against
 * each other, and that beating is the entire reason a string section sounds like
 * a section rather than one very loud violin — it is also why `detune` is in
 * cents rather than hertz, because the beat rate has to scale with the pitch.
 */
export function voice (frequency, length, { engine = 'sine', timbre = {}, envelope, sampleRate, rng, unison = 1, detune = 0 }) {
  const out = new Float32Array(length)

  for (let u = 0; u < unison; u++) {
    const offset = unison === 1 ? 0 : (u / (unison - 1)) * 2 - 1
    const f = frequency * (2 ** (offset * detune / 1200))
    const partial = tone(engine, f, length, sampleRate, timbre)

    // A random start phase per copy. Identical phases sum to one louder voice
    // with a transient spike at the attack rather than to a chorus.
    const skip = rng ? Math.floor(rng.float() * 64) : 0

    for (let i = 0; i < length; i++) out[i] += partial[(i + skip) % length] / unison
  }

  const shape = adsr(length, envelope, sampleRate)
  for (let i = 0; i < length; i++) out[i] *= shape[i]

  return out
}

/**
 * The three kits — docs/09 §7.
 *
 * Every kit is the same four synthesis routines with different numbers; nothing
 * here is sampled, and there is no code path that only one kit takes. The
 * differences that matter are decay length and pitch: an acoustic kick is a
 * short thump, an 808 kick is a sine that rings for the best part of a second,
 * and a wood block is neither.
 */
export const KITS = {
  acoustic: {
    kick: { type: 'kick', from: 120, to: 48, sweep: 0.05, length: 0.45, click: 0.25 },
    snare: { type: 'snare', tones: [190, 330], noise: 0.8, centre: 1800, q: 0.8, length: 0.28 },
    hat: { type: 'hat', cutoff: 7500, length: 0.06 },
    perc: { type: 'kick', from: 260, to: 170, sweep: 0.12, length: 0.35, click: 0.05 },
  },
  electronic: {
    kick: { type: 'kick', from: 150, to: 38, sweep: 0.09, length: 0.9, click: 0.15 },
    snare: { type: 'snare', tones: [180, 0], noise: 1.0, centre: 2400, q: 1.2, length: 0.22 },
    hat: { type: 'hat', cutoff: 9000, length: 0.04 },
    perc: { type: 'fm', frequency: 540, ratio: 1.48, index: 6, length: 0.3 },
  },
  wood: {
    kick: { type: 'kick', from: 190, to: 95, sweep: 0.03, length: 0.22, click: 0.35 },
    snare: { type: 'snare', tones: [400, 0], noise: 0.45, centre: 3000, q: 2.2, length: 0.12 },
    hat: { type: 'hat', cutoff: 6000, length: 0.05 },
    // A clave is very nearly a pure tone with a 60 ms decay. Irrational FM ratio
    // because a clave is a struck bar, and struck bars are inharmonic.
    perc: { type: 'fm', frequency: 2400, ratio: 1.41, index: 2.5, length: 0.09 },
  },
}

/** One drum hit, rendered into its own buffer. */
export function drum (spec, sampleRate, rng) {
  const length = Math.max(1, Math.round(spec.length * sampleRate))
  const out = new Float32Array(length)

  switch (spec.type) {
    case 'kick': {
      let phase = 0

      for (let i = 0; i < length; i++) {
        const t = i / length
        // Exponential pitch drop, not linear. A kick is a drum head losing
        // tension, and the ear hears pitch logarithmically — a linear sweep
        // spends most of its time at the top and sounds like a laser.
        const f = spec.to + (spec.from - spec.to) * Math.exp(-i / (spec.sweep * sampleRate))
        phase += 2 * Math.PI * f / sampleRate

        const body = Math.sin(phase) * Math.exp(-4.5 * t)
        // A short noise burst for the beater. Without it a kick is felt and not
        // heard, and disappears entirely on a laptop speaker.
        const click = spec.click * (rng.float() * 2 - 1) * Math.exp(-i / (0.004 * sampleRate))

        out[i] = body + click
      }

      break
    }

    case 'snare': {
      const noise = new Float32Array(length)
      for (let i = 0; i < length; i++) noise[i] = rng.float() * 2 - 1

      const band = biquad(noise, bandpass(spec.centre, spec.q, sampleRate))

      for (let i = 0; i < length; i++) {
        const t = i / length
        let body = 0

        for (const f of spec.tones) {
          if (f > 0) body += Math.sin(2 * Math.PI * f * i / sampleRate) * 0.5
        }

        // The noise outlives the tones: a snare's rattle is the wires under the
        // head continuing after the head itself has stopped.
        out[i] = body * Math.exp(-14 * t) + band[i] * spec.noise * Math.exp(-7 * t)
      }

      break
    }

    case 'hat': {
      const noise = new Float32Array(length)
      for (let i = 0; i < length; i++) noise[i] = rng.float() * 2 - 1

      const bright = biquad(biquad(noise, highpass(spec.cutoff, 0.8, sampleRate)), highpass(spec.cutoff, 0.8, sampleRate))

      for (let i = 0; i < length; i++) out[i] = bright[i] * Math.exp(-24 * i / length)

      break
    }

    default: {
      const partial = fm(spec.frequency, length, sampleRate, spec)
      for (let i = 0; i < length; i++) out[i] = partial[i] * Math.exp(-9 * i / length)
    }
  }

  /*
   * Four milliseconds of fade at the tail.
   *
   * Every decay here is exponential, so the buffer ends at a small but non-zero
   * amplitude and stops there — a step discontinuity, which is broadband, and
   * therefore a click on the end of every single hit. Found by measuring, not by
   * listening: the onset detector in scripts/analyse-audio.mjs reported twice as
   * many onsets as the pattern had, the spurious ones landing exactly one kick
   * length after each real one.
   */
  const fade = Math.min(length, Math.round(0.004 * sampleRate))
  for (let i = 0; i < fade; i++) out[length - 1 - i] *= i / fade

  return out
}

/**
 * Mix a mono source into a stereo pair.
 *
 * Equal *power*, not equal amplitude: a source panned hard left and the same
 * source centred should be the same loudness, and loudness goes with the square
 * of amplitude. Linear panning leaves a 3 dB hole in the middle of the image,
 * which is audible as a part that gets quieter every time it moves to the centre.
 */
export function pan (channels, source, offset, position, gain = 1) {
  const theta = (Math.max(-1, Math.min(1, position)) + 1) * Math.PI / 4

  mix(channels[0], source, offset, gain * Math.cos(theta))
  mix(channels[1], source, offset, gain * Math.sin(theta))

  return channels
}

/** Silent output buffers: `count` channels of `length` samples. */
export function buffers (count, length) {
  return Array.from({ length: count }, () => new Float32Array(length))
}
