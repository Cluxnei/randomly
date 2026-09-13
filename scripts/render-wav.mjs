/**
 * Render an audio score to a WAV file, under Node, with no browser.
 *
 * The API promises `audio/wav` and a caller should not have to reimplement the
 * synthesiser to get it. This runs the *same* modules the browser runs — engine.js
 * and its generators — so a WAV downloaded from the API and one exported from the
 * studio are the same bytes. A second implementation in PHP would have been a
 * second thing to keep in step, and it would have drifted.
 *
 *   node scripts/render-wav.mjs <payload.json> <out.wav> [maxSeconds]
 */
import { writeFileSync, readFileSync } from 'node:fs'
import { Rng } from '../resources/js/rng.js'
import { render } from '../resources/js/audio/engine.js'
import { encodeWav } from '../resources/js/audio/wav.js'

const SAMPLE_RATE = 44100

const [payloadPath, out, maxSeconds = '60'] = process.argv.slice(2)
const payload = JSON.parse(readFileSync(payloadPath, 'utf8'))

const rng = await Rng.from(payload.render_key, 'randomly/render', 65536)
const started = performance.now()
const { channels, duration } = await render(payload.value, rng, SAMPLE_RATE)

// A score can ask for a long piece; a request should not be able to ask the server
// for ten minutes of synthesis. Truncate rather than refuse, so a caller still gets
// usable audio and can see from `duration` that it was cut.
const limit = Math.round(Number(maxSeconds) * SAMPLE_RATE)
const truncated = channels[0].length > limit
const trimmed = truncated ? channels.map(c => c.subarray(0, limit)) : channels

writeFileSync(out, Buffer.from(encodeWav(trimmed, SAMPLE_RATE)))

console.log(JSON.stringify({
  out,
  duration: Number((trimmed[0].length / SAMPLE_RATE).toFixed(2)),
  full_duration: Number(duration.toFixed(2)),
  truncated,
  channels: trimmed.length,
  ms: Math.round(performance.now() - started),
}))
