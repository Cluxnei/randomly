/**
 * The audio harness: score in, samples out.
 *
 * The exact counterpart of canvas.js one dimension down. The server sends a
 * *score* — events, envelopes and synthesis parameters — plus the 32-byte render
 * key, and this derives the stream, dispatches to the right generator and brings
 * the result through a master limiter. A four-minute piece is about two kilobytes
 * of JSON; no audio file is ever written, stored or served.
 *
 * Everything downstream of here is plain JavaScript writing into Float32Arrays.
 * That is deliberate and it is the reason the module can be tested at all: Web
 * Audio nodes only exist inside a browser, and audio's failure modes are silent
 * ones — pink noise that is actually brown, a limiter that never engages, a
 * string playing the wrong note. None of those can be caught by listening in a
 * code review. The identical code runs under Node in scripts/analyse-audio.mjs,
 * where the output is measured. Web Audio's only job is playing the finished
 * buffer, and that lives in player.js.
 */
import { Rng } from '../rng.js'
import { limit } from './dsp.js'
import { render as ambient } from './ambient.js'
import { render as bleep } from './bleep.js'
import { render as chord } from './chord.js'
import { render as drone } from './drone.js'
import { render as melody } from './melody.js'
import { render as noise } from './noise.js'
import { render as pluck } from './pluck.js'
import { render as rhythm } from './rhythm.js'

const ENGINES = { noise, rhythm, melody, pluck, chord, drone, bleep, ambient }

/**
 * How much HKDF stream a render derives.
 *
 * One flat number rather than a per-generator budget, because every generator
 * here spends the same 16 bytes: seeding the per-sample PRNG described in
 * synth.js. A kilobyte is three orders of magnitude more than any of them read
 * and still one HMAC block away from free. HKDF output is a prefix, so asking for
 * more never changes a byte anyone else would have read.
 */
export const STREAM_BYTES = 1024

/** The master ceiling, matching the limiter default in dsp.js — docs/09 §9. */
export const CEILING = 0.89

export function isRenderable (algorithm) {
  return algorithm in ENGINES
}

/**
 * Limit the channels together, from one shared gain envelope.
 *
 * Limiting each channel on its own would be wrong in a way that is easy to miss:
 * a loud transient on the left pulls the left down and leaves the right where it
 * was, so the whole image lurches sideways every time something peaks. The fix is
 * one detector fed by the loudest channel at each instant.
 *
 * The gain curve is recovered by dividing the limiter's own output by its input —
 * `limit` computes out[i] = in[i]·g[i], so out/in is exactly g, and there is no
 * second copy of the attack and release logic here to drift out of step with the
 * one in dsp.js.
 */
/**
 * Match perceived loudness across generators before limiting.
 *
 * Measured RMS of the five defaults spanned 0.011 (pluck) to 0.077 (melody) —
 * close to 17 dB. Someone moving between generators in the studio would get a
 * jolt each time, and would end up riding the volume control instead of listening
 * to the output. Peak normalisation does not fix this: sparse plucked notes have
 * tall transients and almost no energy between them, so they measure loud and
 * sound quiet. RMS is the cheap stand-in for loudness that gets this right.
 *
 * Gain is capped rather than applied blindly, because a near-silent render
 * (a rhythm with one onset, a drone fading out) would otherwise have its noise
 * floor hauled up to full scale.
 */
const REFERENCE_RMS = 0.2

function levelTo (channels, targetRms, maximumGain = 8) {
  let sum = 0
  let count = 0

  for (const channel of channels) {
    for (let i = 0; i < channel.length; i++) {
      sum += channel[i] * channel[i]
      count++
    }
  }

  const rms = Math.sqrt(sum / Math.max(1, count))
  if (rms === 0) return 1

  return Math.min(maximumGain, targetRms / rms)
}

function master (channels, gain) {
  const length = channels[0].length
  const peak = new Float32Array(length)

  // Normalise to an internal reference first, *then* apply the score's own volume.
  // Folding the two together would make the declared volume meaningless: −12 dBFS
  // would mean a different thing for every generator, which is the problem this
  // is here to solve. At the 0.25 default this lands around 0.05 RMS with peaks
  // near −12 dBFS, which is where the limiter shapes transients rather than
  // flattening the body of the sound.
  gain *= levelTo(channels, REFERENCE_RMS)

  for (let i = 0; i < length; i++) {
    let loudest = 0
    for (const channel of channels) {
      channel[i] *= gain
      loudest = Math.max(loudest, Math.abs(channel[i]))
    }
    peak[i] = loudest
  }

  const limited = limit(peak, CEILING)

  for (let i = 0; i < length; i++) {
    if (peak[i] === 0) continue

    const reduction = limited[i] / peak[i]
    for (const channel of channels) channel[i] *= reduction
  }

  return channels
}

/**
 * Render a score.
 *
 * `rng` must be an Rng carrying at least STREAM_BYTES of derived stream. The
 * sample rate defaults to whatever the score declares, so a test and the studio
 * measure the same thing.
 */
export function render (score, rng, sampleRate = score.sample_rate ?? 44100) {
  const engine = ENGINES[score.algorithm]

  if (!engine) {
    throw new Error(`No audio engine for [${score.algorithm}].`)
  }

  const channels = master(engine(score, rng, sampleRate), score.gain ?? 1)

  return {
    channels,
    // Read back from the buffer rather than echoed from the score: the WAV
    // length, the progress bar and the played duration all have to agree, and
    // the samples are the only one of those that cannot be wrong.
    duration: channels[0].length / sampleRate,
    sampleRate,
  }
}

/**
 * Render from an API payload — the studio's entry point.
 *
 * Returns the same `{ milliseconds }` shape the canvas renderers do, because the
 * studio prints it next to the server's own duration and the contrast between
 * deciding what to play and actually playing it is worth showing honestly.
 */
export async function renderFromKey (payload, sampleRate) {
  const score = payload.value
  const rng = await Rng.from(payload.render_key, 'randomly/audio', STREAM_BYTES)
  const started = performance.now()
  const result = render(score, rng, sampleRate ?? score.sample_rate)

  return {
    ...result,
    milliseconds: Math.round(performance.now() - started),
    samples: result.channels[0].length * result.channels.length,
  }
}
