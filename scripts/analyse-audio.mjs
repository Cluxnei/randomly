/**
 * Measure a generated signal instead of listening to it.
 *
 * Audio is the one module whose output cannot be checked by looking, and the
 * failures are specific and silent: pink noise that is actually brown, an
 * envelope that clicks, a limiter that never engages. All of those are obvious in
 * a spectrum and inaudible in a code review.
 *
 * Spectral slope is the headline number. Ideal colours, in dB per octave:
 *   white 0 · pink −3 · brown −6 · blue +3 · violet +6
 *
 * Two modes:
 *
 *   node scripts/analyse-audio.mjs <colour|karplus> [seconds]
 *       one dsp.js primitive, drawn straight from the HKDF stream.
 *
 *   node scripts/analyse-audio.mjs score <score.json> [render-key-hex]
 *       a real generator's score, rendered through resources/js/audio/engine.js —
 *       the same code the browser runs, master limiter included. This is the mode
 *       the Pest suite uses: PHP writes the score its generator emitted, node
 *       renders it, and the measurements come back as JSON.
 */
import { readFileSync } from 'node:fs'
import { Rng } from '../resources/js/rng.js'
import { biquad, pinkNoise, whiteNoise, brownNoise, differentiate, karplusStrong, limit } from '../resources/js/audio/dsp.js'
import { highpass } from '../resources/js/audio/filters.js'
import { STREAM_BYTES, render } from '../resources/js/audio/engine.js'

const SAMPLE_RATE = 44100

/** Iterative radix-2 Cooley-Tukey, in place, on a power-of-two buffer. */
function fft (re, im) {
  const n = re.length

  for (let i = 1, j = 0; i < n; i++) {
    let bit = n >> 1
    for (; j & bit; bit >>= 1) j ^= bit
    j ^= bit
    if (i < j) {
      [re[i], re[j]] = [re[j], re[i]]
      ;[im[i], im[j]] = [im[j], im[i]]
    }
  }

  for (let len = 2; len <= n; len <<= 1) {
    const angle = -2 * Math.PI / len
    const wRe = Math.cos(angle)
    const wIm = Math.sin(angle)

    for (let i = 0; i < n; i += len) {
      let curRe = 1
      let curIm = 0

      for (let k = 0; k < len / 2; k++) {
        const aRe = re[i + k]
        const aIm = im[i + k]
        const bRe = re[i + k + len / 2] * curRe - im[i + k + len / 2] * curIm
        const bIm = re[i + k + len / 2] * curIm + im[i + k + len / 2] * curRe

        re[i + k] = aRe + bRe
        im[i + k] = aIm + bIm
        re[i + k + len / 2] = aRe - bRe
        im[i + k + len / 2] = aIm - bIm

        const nextRe = curRe * wRe - curIm * wIm
        curIm = curRe * wIm + curIm * wRe
        curRe = nextRe
      }
    }
  }
}

/**
 * Average the power spectrum over many windows.
 *
 * One window of noise is itself noisy — the per-bin variance of a periodogram
 * does not shrink with window length, only with averaging. Welch's method over
 * dozens of windows is what turns a jagged mess into a measurable slope.
 */
function spectrum (signal, windowSize = 8192) {
  const windows = Math.floor(signal.length / windowSize)
  const power = new Float64Array(windowSize / 2)

  for (let w = 0; w < windows; w++) {
    const re = new Float64Array(windowSize)
    const im = new Float64Array(windowSize)

    for (let i = 0; i < windowSize; i++) {
      // Hann window: without it the discontinuity at each window edge leaks
      // broadband energy and flattens whatever slope we are trying to measure.
      const hann = 0.5 * (1 - Math.cos(2 * Math.PI * i / (windowSize - 1)))
      re[i] = signal[w * windowSize + i] * hann
    }

    fft(re, im)

    for (let k = 0; k < power.length; k++) {
      power[k] += re[k] * re[k] + im[k] * im[k]
    }
  }

  for (let k = 0; k < power.length; k++) power[k] /= windows

  return power
}

/** Least-squares slope of dB against log2(frequency), across the audible band. */
function slopePerOctave (power, sampleRate, from = 40, to = 16000) {
  const binHz = sampleRate / (power.length * 2)
  const points = []

  // Octave bands rather than raw bins: bins are linearly spaced, so a straight
  // regression over them would weight the top octave more than all the others
  // combined and report a slope dominated by the treble.
  for (let f = from; f <= to; f *= 2 ** 0.5) {
    const lo = Math.max(1, Math.floor(f / 2 ** 0.25 / binHz))
    const hi = Math.min(power.length - 1, Math.ceil(f * 2 ** 0.25 / binHz))
    if (hi <= lo) continue

    let sum = 0
    for (let k = lo; k <= hi; k++) sum += power[k]

    const mean = sum / (hi - lo + 1)
    if (mean > 0) points.push([Math.log2(f), 10 * Math.log10(mean)])
  }

  const n = points.length
  const sx = points.reduce((a, p) => a + p[0], 0)
  const sy = points.reduce((a, p) => a + p[1], 0)
  const sxy = points.reduce((a, p) => a + p[0] * p[1], 0)
  const sxx = points.reduce((a, p) => a + p[0] * p[0], 0)

  return (n * sxy - sx * sy) / (n * sxx - sx * sx)
}

/**
 * Fundamental frequency by autocorrelation.
 *
 * Not "the tallest spectral peak" — that finds the loudest *harmonic*, which is
 * often not the first. Karplus-Strong is excited by noise, so the energy lands
 * unevenly across the series exactly as it does on a real string depending where
 * you pluck it; a run measured here had the third harmonic above the fundamental.
 * Autocorrelation asks the right question instead: at what lag does the waveform
 * most resemble itself?
 */
function fundamental (signal, sampleRate, minHz = 60, maxHz = 2000) {
  const minLag = Math.floor(sampleRate / maxHz)
  const maxLag = Math.ceil(sampleRate / minHz)
  const window = Math.min(signal.length, sampleRate)

  const scores = new Float64Array(maxLag + 1)

  /*
   * Normalised by the energy of the two segments being compared, not by the
   * overlap length.
   *
   * Dividing by the length alone leaves the estimate biased by the *envelope*:
   * where the signal is growing, a shorter lag pairs quiet samples with samples
   * that are only slightly louder, while a longer lag pairs them with much
   * louder ones — and the sum is dominated by amplitude rather than by shape.
   * Measured on the drone generator, whose four-second fade-in means the whole
   * analysis window is a ramp: it reported 117 Hz for a piece built on 110.
   *
   * Dividing by √(E₁·E₂) is the Pearson form and cancels the envelope exactly,
   * which leaves the question the measurement is supposed to be asking: at what
   * lag does the waveform have the same *shape*, whatever its level?
   */
  const energy = new Float64Array(window + 1)
  for (let i = 0; i < window; i++) energy[i + 1] = energy[i] + signal[i] * signal[i]

  for (let lag = minLag; lag <= maxLag; lag++) {
    let sum = 0
    for (let i = 0; i < window - lag; i++) sum += signal[i] * signal[i + lag]

    const head = energy[window - lag]
    const tail = energy[window] - energy[lag]

    scores[lag] = head > 0 && tail > 0 ? sum / Math.sqrt(head * tail) : 0
  }

  /*
   * Ignore every lag before the correlation first goes negative.
   *
   * A waveform resembles itself at lag zero and at every small lag near it —
   * a 110 Hz drone has a period of 401 samples, so at lag 22 it has barely moved
   * and correlates at 94% of its maximum. Searching from the shortest lag
   * upward therefore reports 2 kHz for a signal whose spectrum is unambiguously
   * a harmonic series on 110 Hz, which is a measurement bug that reads exactly
   * like a synthesis one. (It was: the drone generator measured 2004.5 Hz.)
   *
   * The standard fix, and the one every pitch tracker uses: a true period has to
   * put the waveform out of phase with itself somewhere in between, so skip
   * ahead to the first lag where the correlation has actually gone negative and
   * only look for peaks beyond it. Signals that never go negative — a filtered
   * noise bed with no pitch in it at all — fall back to the whole range, because
   * for those there is no right answer to protect.
   */
  let start = minLag
  while (start <= maxLag && scores[start] > 0) start++
  if (start > maxLag) start = minLag

  let best = -Infinity
  for (let lag = start; lag <= maxLag; lag++) best = Math.max(best, scores[lag])

  /*
   * Take the earliest *peak* that comes close to the best score — not the
   * earliest lag that crosses the threshold, and not the best score outright.
   *
   * Both of the obvious rules are wrong, in opposite directions. Taking the
   * highest score gets octave errors: autocorrelation peaks at every multiple of
   * the true period, and on a decaying note the longer lag can edge ahead on
   * noise alone — a 196 Hz string measuring 98.2 Hz. Taking the first lag over
   * 90% of it gets the other error, because around a peak the curve is broad:
   * the drone's correlation passes 90% at lag 376 on its way to a maximum at
   * 400, so a 110 Hz piece measured 117 Hz. Requiring a local maximum asks for
   * the thing a period actually is.
   *
   * The peak is then interpolated parabolically through its two neighbours. The
   * lag is an integer number of samples and the true period is not — at 110 Hz
   * one sample is a quarter of a percent, which is the difference between
   * measuring a note and measuring the sample rate.
   */
  for (let lag = start + 1; lag < maxLag; lag++) {
    if (scores[lag] < best * 0.9) continue
    if (scores[lag] < scores[lag - 1] || scores[lag] < scores[lag + 1]) continue

    const curvature = scores[lag - 1] - 2 * scores[lag] + scores[lag + 1]
    const offset = curvature === 0 ? 0 : 0.5 * (scores[lag - 1] - scores[lag + 1]) / curvature

    return sampleRate / (lag + offset)
  }

  return sampleRate / minLag
}

/** Energy in the last tenth against the first tenth: below 1 means it decays. */
function decayRatio (signal) {
  const window = Math.floor(signal.length / 10)
  const rmsOf = (from) => {
    let sum = 0
    for (let i = from; i < from + window; i++) sum += signal[i] * signal[i]
    return Math.sqrt(sum / window)
  }

  const head = rmsOf(0)
  return head === 0 ? 0 : rmsOf(signal.length - window) / head
}

/**
 * Levels in half-octave bands, in dB.
 *
 * Slope is the right summary for a signal that is a straight line on a log plot,
 * and the wrong one for grey noise, which is deliberately *not* a straight line —
 * it traces the inverse of the ear's own response and has a bowl in it around
 * 3 kHz. Bands let a test assert the shape rather than a line of best fit
 * through it.
 */
function bandLevels (power, sampleRate, from = 60, to = 14000) {
  const binHz = sampleRate / (power.length * 2)
  const bands = {}

  for (let f = from; f <= to; f *= 2) {
    const lo = Math.max(1, Math.floor(f / 2 ** 0.5 / binHz))
    const hi = Math.min(power.length - 1, Math.ceil(f * 2 ** 0.5 / binHz))
    if (hi <= lo) continue

    let sum = 0
    for (let k = lo; k <= hi; k++) sum += power[k]

    bands[Math.round(f)] = Number((10 * Math.log10(sum / (hi - lo + 1))).toFixed(2))
  }

  return bands
}

/**
 * Where the transients are, in seconds.
 *
 * Measured on a high-passed copy, which is the detail that makes this work at
 * all. A kick drum's body is a 50 Hz sine, and 50 Hz completes a cycle every
 * 20 ms — so short-time energy computed on the raw signal *rises* once per cycle
 * and a naive detector reports thirty onsets where there was one hit. Above about
 * 1.2 kHz there is nothing left but the attack transient, which every piece of
 * every kit has and which decays within a few milliseconds.
 *
 * This is how "the onsets land where the tempo implies" gets checked against the
 * samples rather than against the score that produced them — a tempo wrong by a
 * factor of two is invisible in the score and obvious here.
 */
function onsets (signal, sampleRate, { frame = 32, threshold = 1.6, minGap = 0.03 } = {}) {
  const transients = biquad(signal, highpass(1200, 0.707, sampleRate))
  const frames = Math.floor(transients.length / frame)
  const energy = new Float64Array(frames)

  for (let f = 0; f < frames; f++) {
    let sum = 0
    for (let i = f * frame; i < (f + 1) * frame; i++) sum += transients[i] * transients[i]
    energy[f] = Math.log(sum / frame + 1e-10)
  }

  /*
   * A floor 35 dB under the loudest frame.
   *
   * Log energy is scale-free, which is what makes the threshold work for a loud
   * kick and a quiet hat alike — and is also why it needs a floor. Down in the
   * gaps between hits the residue is a few samples of filter ringing, and a
   * factor-of-five wobble in something 60 dB down is the same "1.6" as a drum
   * hit. Without this the detector reports the noise floor breathing.
   */
  const silence = Math.log(1e-10)

  // Looped rather than Math.max(...energy): a 77-second render is 100,000 frames
  // and spreading that into an argument list overflows the call stack. The
  // failure is a RangeError from a line that looks like arithmetic.
  let loudest = -Infinity
  for (let f = 0; f < frames; f++) loudest = Math.max(loudest, energy[f])

  const floor = loudest - 35 * Math.LN10 / 10
  const lookback = Math.max(1, Math.round(0.01 * sampleRate / frame))

  const found = []
  let last = -Infinity

  for (let f = 0; f < frames - 1; f++) {
    // The *first* frame of the rising edge, not the loudest one. An attack takes
    // a few frames to reach its peak, and the question being asked is when the
    // hit started — picking the maximum would report every onset a millisecond
    // or two late and turn a timing assertion into a fudge factor.
    //
    // Frame zero is compared against silence rather than against nothing: a
    // piece whose first hit is on sample zero has no quiet frame in front of it,
    // and would otherwise be the one onset the detector always missed.
    if (energy[f] < floor) continue

    // Compared against the loudest of the previous 10 ms rather than against the
    // single frame before. A drum that is still decaying has a *falling*
    // envelope with ripples in it, and one ripple against its immediate
    // predecessor looks exactly like a new hit; against the recent maximum it
    // cannot, because the recent maximum is the hit it is decaying from.
    let previous = f === 0 ? silence : -Infinity
    for (let k = Math.max(0, f - lookback); k < f; k++) previous = Math.max(previous, energy[k])

    if (energy[f] - previous < threshold) continue

    const at = f * frame / sampleRate
    if (at - last < minGap) continue

    found.push(Number(at.toFixed(4)))
    last = at
  }

  return found
}

/** Peak and RMS of one signal. */
function levels (signal) {
  let peak = 0
  let rms = 0

  for (let i = 0; i < signal.length; i++) {
    const v = Math.abs(signal[i])
    if (v > peak) peak = v
    rms += signal[i] * signal[i]
  }

  return { peak, rms: Math.sqrt(rms / signal.length) }
}

/** Channels summed to mono, which is what the spectrum and pitch measures want. */
function monoOf (channels) {
  if (channels.length === 1) return channels[0]

  const out = new Float32Array(channels[0].length)
  for (let i = 0; i < out.length; i++) {
    let sum = 0
    for (const channel of channels) sum += channel[i]
    out[i] = sum / channels.length
  }

  return out
}

const argv = process.argv.slice(2)

if (argv[0] === 'score') {
  /*
   * A generator's own score, through the same engine the browser runs.
   *
   * Measuring the primitives proves dsp.js is right; measuring this proves the
   * generators use it right, which is a different and more interesting question.
   * A pluck generator that emits the wrong MIDI number, a rhythm that halves its
   * own tempo and a limiter that a score bypasses are all invisible in the unit
   * that each of those pieces passes on its own.
   */
  const score = JSON.parse(readFileSync(argv[1], 'utf8'))
  const key = argv[2] ?? '42'.repeat(32)
  const sampleRate = score.sample_rate ?? SAMPLE_RATE

  const rng = await Rng.from(key, 'randomly/audio', STREAM_BYTES)
  const started = performance.now()
  const rendered = render(score, rng, sampleRate)
  const milliseconds = Math.round(performance.now() - started)

  const mono = monoOf(rendered.channels)
  const measured = rendered.channels.map(levels)
  const power = spectrum(mono)

  console.log(JSON.stringify({
    signal: score.algorithm,
    channels: rendered.channels.length,
    duration: Number(rendered.duration.toFixed(4)),
    sample_rate: sampleRate,
    render_ms: milliseconds,
    slope_db_per_octave: Number(slopePerOctave(power, sampleRate).toFixed(2)),
    fundamental_hz: Number(fundamental(mono, sampleRate).toFixed(1)),
    decay_ratio: Number(decayRatio(mono).toFixed(4)),
    // Across every channel: the limiter ceiling is a promise about the file, and
    // a right channel over the line is as much a clip as a left one.
    peak: Number(Math.max(...measured.map(m => m.peak)).toFixed(4)),
    rms: Number(Math.max(...measured.map(m => m.rms)).toFixed(4)),
    bands: bandLevels(power, sampleRate),
    onsets: onsets(mono, sampleRate),
  }))
} else {
  const [what = 'pink', seconds = '4'] = argv
  const length = Math.round(Number(seconds) * SAMPLE_RATE)
  const rng = await Rng.from('42'.repeat(32), 'randomly/audio', length * 8 + 65536)

  const signal = {
    white: () => whiteNoise(length, rng),
    pink: () => pinkNoise(length, rng),
    brown: () => brownNoise(length, rng),
    // Blue is pink differentiated (−3 + 6 = +3); violet is white differentiated
    // (0 + 6 = +6). See the note on differentiate() in dsp.js.
    blue: () => differentiate(pinkNoise(length, rng), 1),
    violet: () => differentiate(whiteNoise(length, rng), 1),
    karplus: () => karplusStrong(length, 220, rng, { sampleRate: SAMPLE_RATE }),
  }[what]()

  const { peak, rms } = levels(signal)

  const limited = limit(Float32Array.from(signal))
  let limitedPeak = 0
  for (let i = 0; i < limited.length; i++) limitedPeak = Math.max(limitedPeak, Math.abs(limited[i]))

  console.log(JSON.stringify({
    signal: what,
    slope_db_per_octave: Number(slopePerOctave(spectrum(signal), SAMPLE_RATE).toFixed(2)),
    fundamental_hz: Number(fundamental(signal, SAMPLE_RATE).toFixed(1)),
    decay_ratio: Number(decayRatio(signal).toFixed(4)),
    peak: Number(peak.toFixed(4)),
    rms: Number(rms.toFixed(4)),
    peak_after_limiter: Number(limitedPeak.toFixed(4)),
  }))
}
