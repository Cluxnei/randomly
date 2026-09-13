/**
 * Playback, export and the waveform — the only place Web Audio appears.
 *
 * Everything that makes a sound in this module is plain JavaScript writing into a
 * Float32Array, for reasons engine.js sets out at length. By the time anything
 * gets here the samples already exist, and Web Audio's entire job is to be the
 * output device: one AudioBufferSourceNode through one gain node.
 *
 * Which is also why the WAV export does not go near an OfflineAudioContext, as
 * docs/09 §1 assumed it would have to. An offline context is for rendering a node
 * graph without playing it; there is no node graph here, so the samples go
 * straight to the encoder and the exported file is bit-for-bit what was played.
 */
import { encodeWav } from './wav.js'

export class Player {
  constructor () {
    this.context = null
    this.source = null
    this.output = null
    this.rendered = null
    this.startedAt = 0
    this.playing = false
    this.volume = 1
    this.onended = null
  }

  /** Hand over a render from engine.js. Stops whatever was playing first. */
  load (rendered) {
    this.stop()
    this.rendered = rendered
  }

  /**
   * Start playing. Must be called from a user gesture — docs/09 §9.
   *
   * Nothing here ever autoplays, and the browser would not allow it anyway: an
   * AudioContext created outside a gesture starts suspended. Those two facts
   * agree with each other, so the deliberate first click costs nothing.
   */
  async play ({ loop = false } = {}) {
    if (!this.rendered) return false

    if (!this.context) {
      const Context = window.AudioContext ?? window.webkitAudioContext
      this.context = new Context()
      this.output = this.context.createGain()
      this.output.connect(this.context.destination)
      this.output.gain.value = this.volume
    }

    // Safari and Chrome both suspend a context created before a gesture, and a
    // resumed context is the difference between a play button that works on the
    // first click and one that works on the second.
    if (this.context.state === 'suspended') await this.context.resume()

    this.stop()

    const { channels, sampleRate } = this.rendered

    /*
     * The buffer is created at the score's sample rate, not the device's.
     *
     * A laptop running its output at 48 kHz would otherwise play a 44.1 kHz
     * render 8.8% fast — every note a semitone and a half sharp, the piece
     * shorter than its own progress bar, and nothing in the code looking wrong.
     * Declaring the rate lets the browser resample, which it does better than
     * this file would.
     */
    const buffer = this.context.createBuffer(channels.length, channels[0].length, sampleRate)
    for (let c = 0; c < channels.length; c++) buffer.copyToChannel(channels[c], c)

    const source = this.context.createBufferSource()
    source.buffer = buffer
    source.loop = loop
    source.connect(this.output)

    source.onended = () => {
      // A looping source only ends because stop() was called, and stop() has
      // already cleared the flag; firing the callback again would tell the studio
      // a piece finished that is about to start.
      if (this.source !== source) return

      this.playing = false
      this.source = null
      if (this.onended) this.onended()
    }

    this.source = source
    this.startedAt = this.context.currentTime
    this.playing = true
    source.start()

    return true
  }

  stop () {
    if (!this.source) return

    const source = this.source
    this.source = null
    this.playing = false

    source.onended = null
    try {
      source.stop()
    } catch {
      // Stopping a node that already ended throws in some browsers and means
      // exactly what was wanted, so there is nothing to report.
    }
  }

  /** Where the playhead is, in seconds. Loops wrap. */
  position () {
    if (!this.playing || !this.context || !this.rendered) return 0

    const elapsed = this.context.currentTime - this.startedAt

    return this.source?.loop ? elapsed % this.rendered.duration : Math.min(elapsed, this.rendered.duration)
  }

  /** 0 to 1, applied live — this is the monitor level, not part of the file. */
  setVolume (value) {
    this.volume = Math.max(0, Math.min(1, value))

    if (this.output) {
      // Ramped rather than assigned. A gain that jumps between two samples is a
      // step in the waveform, which is a click — the one sound a volume control
      // must never make.
      this.output.gain.setTargetAtTime(this.volume, this.context.currentTime, 0.015)
    }
  }

  /** Release the device. A suspended context costs a browser nothing to keep. */
  async suspend () {
    this.stop()

    if (this.context && this.context.state === 'running') await this.context.suspend()
  }

  wav () {
    if (!this.rendered) return null

    return encodeWav(this.rendered.channels, this.rendered.sampleRate)
  }
}

/**
 * Hand the listener a WAV.
 *
 * 16-bit PCM, uncompressed, at the score's own sample rate — the format that
 * needs no explanation and opens in everything. The file is the render, not a
 * re-performance of it.
 */
export function downloadWav (rendered, filename = 'randomly.wav') {
  const blob = new Blob([encodeWav(rendered.channels, rendered.sampleRate)], { type: 'audio/wav' })
  const url = URL.createObjectURL(blob)

  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()

  URL.revokeObjectURL(url)

  return blob.size
}

/**
 * Min and max per horizontal pixel, which is how a waveform is actually drawn.
 *
 * Sampling one value per pixel would be aliasing in the most literal sense: a
 * 25-second render is a million samples across 800 pixels, and taking every
 * 1300th of them draws a picture of whatever those particular samples happened
 * to be doing. Keeping both extremes of each bucket draws the envelope, which is
 * what a listener is looking for.
 */
export function peaks (channels, buckets) {
  const length = channels[0].length
  const span = Math.max(1, Math.floor(length / buckets))
  const out = []

  for (let b = 0; b < buckets; b++) {
    const from = b * span
    const to = Math.min(length, from + span)

    let low = 0
    let high = 0

    for (let i = from; i < to; i++) {
      for (const channel of channels) {
        if (channel[i] < low) low = channel[i]
        if (channel[i] > high) high = channel[i]
      }
    }

    out.push([low, high])
  }

  return out
}
