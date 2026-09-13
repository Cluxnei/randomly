/**
 * 16-bit PCM WAV encoding.
 *
 * Written by hand rather than pulled from a package: it is one header and a
 * sample loop, it has to run unchanged in both the browser and Node, and the
 * export path is the one place a dependency would be least welcome.
 */
export function encodeWav (channels, sampleRate = 44100) {
  const channelCount = channels.length
  const frames = channels[0].length
  const blockAlign = channelCount * 2
  const dataBytes = frames * blockAlign

  const buffer = new ArrayBuffer(44 + dataBytes)
  const view = new DataView(buffer)

  const ascii = (offset, text) => {
    for (let i = 0; i < text.length; i++) view.setUint8(offset + i, text.charCodeAt(i))
  }

  ascii(0, 'RIFF')
  view.setUint32(4, 36 + dataBytes, true)
  ascii(8, 'WAVE')
  ascii(12, 'fmt ')
  view.setUint32(16, 16, true)          // PCM header size
  view.setUint16(20, 1, true)           // format: uncompressed PCM
  view.setUint16(22, channelCount, true)
  view.setUint32(24, sampleRate, true)
  view.setUint32(28, sampleRate * blockAlign, true)
  view.setUint16(32, blockAlign, true)
  view.setUint16(34, 16, true)          // bits per sample
  ascii(36, 'data')
  view.setUint32(40, dataBytes, true)

  let offset = 44
  for (let frame = 0; frame < frames; frame++) {
    for (let c = 0; c < channelCount; c++) {
      // Clamp before scaling: a sample past ±1 would wrap to the opposite rail
      // and turn a moment of loudness into a burst of noise.
      const sample = Math.max(-1, Math.min(1, channels[c][frame]))
      view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true)
      offset += 2
    }
  }

  return buffer
}
