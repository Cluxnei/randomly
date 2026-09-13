/**
 * Draw a rendered score as a waveform PNG.
 *
 * Not a substitute for the spectral measurements, but it catches a different
 * class of problem at a glance: a rhythm whose onsets are uneven, an envelope
 * that clicks, silence where notes should be, a limiter squashing everything
 * flat. Reuses the PNG encoder from render-preview.mjs.
 */
import { deflateSync } from 'node:zlib'
import { writeFileSync, readFileSync } from 'node:fs'
import { Rng } from '../resources/js/rng.js'
import { render } from '../resources/js/audio/engine.js'

function crc32 (buf) {
  let c, crc = 0xffffffff
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    crc = (crc >>> 8) ^ c
  }
  return (crc ^ 0xffffffff) >>> 0
}

function chunk (type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data])
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(body))
  return Buffer.concat([len, body, crc])
}

function png (w, h, rgba) {
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 6
  const raw = Buffer.alloc(h * (w * 4 + 1))
  for (let y = 0; y < h; y++) {
    raw[y * (w * 4 + 1)] = 0
    Buffer.from(rgba.buffer, y * w * 4, w * 4).copy(raw, y * (w * 4 + 1) + 1)
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0)),
  ])
}

const payload = JSON.parse(readFileSync(process.argv[2], 'utf8'))
const out = process.argv[3]
const W = 900, H = 220

const rng = await Rng.from(payload.render_key, 'randomly/render', 65536)
const { channels, duration } = await render(payload.value, rng, 44100)
const signal = channels[0]

const rgba = new Uint8ClampedArray(W * H * 4)
for (let i = 0; i < W * H; i++) { rgba[i * 4] = 12; rgba[i * 4 + 1] = 14; rgba[i * 4 + 2] = 20; rgba[i * 4 + 3] = 255 }

const put = (x, y, r, g, b) => {
  if (x < 0 || x >= W || y < 0 || y >= H) return
  const i = (y * W + x) * 4
  rgba[i] = r; rgba[i + 1] = g; rgba[i + 2] = b
}

for (let x = 0; x < W; x++) put(x, H >> 1, 40, 46, 60)

// Min/max per column, which is how a DAW draws it — a plain decimation would
// alias transients away and make a clicky render look clean.
const per = Math.max(1, Math.floor(signal.length / W))
for (let x = 0; x < W; x++) {
  let lo = 0, hi = 0
  for (let k = 0; k < per; k++) {
    const v = signal[x * per + k] || 0
    if (v < lo) lo = v
    if (v > hi) hi = v
  }
  const y0 = Math.round((1 - hi) * H / 2)
  const y1 = Math.round((1 - lo) * H / 2)
  for (let y = y0; y <= y1; y++) put(x, y, 120, 240, 150)
}

writeFileSync(out, png(W, H, rgba))

// Looped, not Math.max(...signal): spreading a multi-megabyte Float32Array into
// an argument list overflows the call stack. Costs nothing, and it is the second
// time this exact line has been written in this project.
let peak = 0
for (let i = 0; i < signal.length; i++) {
  const v = Math.abs(signal[i])
  if (v > peak) peak = v
}

console.log(JSON.stringify({ out, duration: Number(duration.toFixed(2)), peak: Number(peak.toFixed(3)) }))
