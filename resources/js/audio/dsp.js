/**
 * Synthesis primitives, in plain JavaScript.
 *
 * Deliberately not built on Web Audio nodes. Generating samples ourselves means
 * the identical code runs in the browser, in an OfflineAudioContext for WAV
 * export, and under Node in scripts/render-audio.mjs — where the output can be
 * measured rather than merely listened to. A pink noise generator that is
 * actually brown is inaudible as a bug and obvious in a spectrum.
 *
 * Everything here is a pure function of its inputs and the Rng, matching the
 * canvas renderers: the server sends a score, the client makes the samples.
 */

/** Linear ADSR. Attack, decay and release are seconds; sustain is a level. */
export function adsr (length, { attack = 0.01, decay = 0.1, sustain = 0.6, release = 0.3 }, sampleRate) {
  const out = new Float32Array(length)
  const a = Math.max(1, Math.round(attack * sampleRate))
  const d = Math.max(1, Math.round(decay * sampleRate))
  const r = Math.max(1, Math.round(release * sampleRate))
  const s = Math.max(0, length - a - d - r)

  let i = 0
  for (let n = 0; n < a && i < length; n++, i++) out[i] = n / a
  for (let n = 0; n < d && i < length; n++, i++) out[i] = 1 + (sustain - 1) * (n / d)
  for (let n = 0; n < s && i < length; n++, i++) out[i] = sustain
  for (let n = 0; n < r && i < length; n++, i++) out[i] = sustain * (1 - n / r)

  return out
}

/**
 * Voss-McCartney pink noise.
 *
 * `rows` white generators, each updating at half the rate of the one before, so
 * the low frequencies change rarely and the high ones every sample. Summing them
 * gives 1/f across the audible band for the price of a handful of adds — the
 * direct alternative is an FFT or a filter cascade.
 *
 * 16 rows covers 2^16 samples ≈ 1.5s at 44.1 kHz before the slowest row repeats
 * its update interval, which is well below the lowest frequency anyone hears.
 */
export function pinkNoise (length, rng, rows = 16) {
  const out = new Float32Array(length)
  const row = new Float32Array(rows)
  let running = 0

  for (let i = 0; i < length; i++) {
    // Only the rows whose update interval divides i change on this sample; the
    // lowest set bit of i tells us how many, which is what makes this O(1).
    let k = 0
    for (let mask = i; k < rows && (mask & 1) === 0; mask >>= 1) k++
    if (k >= rows) k = rows - 1

    running -= row[k]
    row[k] = rng.float() * 2 - 1
    running += row[k]

    out[i] = running / rows
  }

  return out
}

export function whiteNoise (length, rng) {
  const out = new Float32Array(length)
  for (let i = 0; i < length; i++) out[i] = rng.float() * 2 - 1
  return out
}

/**
 * Brown noise: integrated white, which is what −6 dB/octave means.
 *
 * The running sum is a random walk and will drift off to one rail given long
 * enough, so it leaks back towards zero. Without the leak a two-minute render
 * ends up silently clipped against the edge of the buffer.
 */
export function brownNoise (length, rng, leak = 0.998) {
  const out = new Float32Array(length)
  let last = 0

  for (let i = 0; i < length; i++) {
    last = last * leak + (rng.float() * 2 - 1) * 0.05
    out[i] = last
  }

  return normalise(out, 0.9)
}

/**
 * Differentiate: **+6 dB per octave, per application.**
 *
 * Worth stating plainly because the obvious reading is wrong and the error is
 * inaudible. Differentiating in time multiplies by jω in frequency, so amplitude
 * scales with f and *power* with f² — and 10·log₁₀(f²) is 6 dB per octave, not 3.
 *
 * So the colours do not map the way one would guess:
 *
 *   violet (+6) = differentiate(white)          0 + 6
 *   blue   (+3) = differentiate(pink)          −3 + 6
 *
 * Differentiating white once for "blue" gives violet, and twice gives +12 dB/oct,
 * which is not a colour anyone named. scripts/analyse-audio.mjs measures the slope
 * and is how this was caught.
 */
export function differentiate (input, times = 1) {
  let signal = input

  for (let t = 0; t < times; t++) {
    const out = new Float32Array(signal.length)
    for (let i = 1; i < signal.length; i++) out[i] = signal[i] - signal[i - 1]
    signal = out
  }

  return normalise(signal, 0.9)
}

/**
 * Karplus-Strong: a burst of noise in a delay line, low-passed on each lap.
 *
 *     y[n] = decay · ½ · (y[n−N] + y[n−N−1])
 *
 * The averaging is a one-pole low pass, so each trip round the loop loses a
 * little more high end — which is exactly what a real string does. The whole
 * plucked-string timbre falls out of noise plus a delay, which makes it the best
 * demonstration on this site of structure emerging from randomness.
 */
export function karplusStrong (length, frequency, rng, { decay = 0.996, sampleRate = 44100 } = {}) {
  const n = Math.max(2, Math.round(sampleRate / frequency))
  const buffer = new Float32Array(n)
  for (let i = 0; i < n; i++) buffer[i] = rng.float() * 2 - 1

  const out = new Float32Array(length)
  let pointer = 0

  for (let i = 0; i < length; i++) {
    const current = buffer[pointer]

    // Average with the *next* slot, which in a circular buffer is the oldest
    // sample — the one from N steps ago. Averaging with the previous slot instead
    // subtracts adjacent samples in effect, which is a high-pass: the string comes
    // out brighter each lap rather than darker, and the spectrum tilts upward. It
    // still sounds like something, which is why this needs measuring rather than
    // listening. scripts/analyse-audio.mjs caught exactly that.
    const oldest = buffer[(pointer + 1) % n]

    out[i] = current
    buffer[pointer] = decay * 0.5 * (current + oldest)

    pointer = (pointer + 1) % n
  }

  return out
}

/** Transposed direct form II biquad — one state pair, no per-sample allocation. */
export function biquad (input, { b0, b1, b2, a1, a2 }) {
  const out = new Float32Array(input.length)
  let z1 = 0
  let z2 = 0

  for (let i = 0; i < input.length; i++) {
    const x = input[i]
    const y = b0 * x + z1
    z1 = b1 * x - a1 * y + z2
    z2 = b2 * x - a2 * y
    out[i] = y
  }

  return out
}

/** RBJ cookbook low-pass coefficients. */
export function lowpass (frequency, q, sampleRate) {
  const w = 2 * Math.PI * frequency / sampleRate
  const alpha = Math.sin(w) / (2 * q)
  const cos = Math.cos(w)
  const a0 = 1 + alpha

  return {
    b0: ((1 - cos) / 2) / a0,
    b1: (1 - cos) / a0,
    b2: ((1 - cos) / 2) / a0,
    a1: (-2 * cos) / a0,
    a2: (1 - alpha) / a0,
  }
}

export function normalise (signal, peak = 0.9) {
  let max = 0
  for (let i = 0; i < signal.length; i++) {
    const v = Math.abs(signal[i])
    if (v > max) max = v
  }

  if (max === 0) return signal

  const gain = peak / max
  for (let i = 0; i < signal.length; i++) signal[i] *= gain

  return signal
}

/**
 * A look-ahead limiter on the master bus.
 *
 * Generative audio has no soundcheck: a rhythm generator can stack four layers
 * that happen to land on the same sample and produce a peak nothing predicted.
 * Hard clipping that peak is audible as a click, so gain is reduced smoothly
 * ahead of it and released slowly afterwards.
 */
export function limit (signal, ceiling = 0.89, attackSamples = 64, releaseSamples = 2048) {
  const out = new Float32Array(signal.length)
  let gain = 1

  for (let i = 0; i < signal.length; i++) {
    let required = 1
    const until = Math.min(signal.length, i + attackSamples)
    for (let k = i; k < until; k++) {
      const peak = Math.abs(signal[k])
      if (peak > ceiling) required = Math.min(required, ceiling / peak)
    }

    gain = required < gain
      ? required
      : gain + (1 - gain) / releaseSamples

    out[i] = signal[i] * gain
  }

  return out
}

/** MIDI note to frequency: f = 440 · 2^((n − 69)/12). */
export function midiToHz (note) {
  return 440 * (2 ** ((note - 69) / 12))
}

export function mix (target, source, offset, gain = 1) {
  const end = Math.min(target.length, offset + source.length)
  for (let i = Math.max(0, offset); i < end; i++) {
    target[i] += source[i - offset] * gain
  }
  return target
}
