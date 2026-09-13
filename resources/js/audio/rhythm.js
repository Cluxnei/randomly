/**
 * Euclidean rhythm — docs/09 §5.
 *
 * The patterns themselves are decided on the server, where Bjorklund's algorithm
 * lives and where they can be tested against their known answers. This half does
 * the drumming: it turns a list of ones and zeroes into hits at sample positions
 * the tempo implies, which is the one thing about a rhythm that cannot be checked
 * by reading the score.
 */
import { KITS, SampleRng, buffers, drum, pan } from './synth.js'

/**
 * When a step lands, in seconds.
 *
 * Swing delays every second step, and that is the whole of it: at a swing of 1/3
 * the pairs land in triplet time, which is where a shuffle comes from. Kept as a
 * function rather than inlined because tests/Unit/AudioRenderTest.php asserts the
 * rendered transients against exactly this formula.
 */
export function onsetSeconds (step, layer, swing) {
  const offset = (step % 2 === 1 ? swing * 0.5 : 0) * layer.step_seconds

  return step * layer.step_seconds + offset
}

export function render (score, rng, sampleRate) {
  const length = Math.round(score.duration * sampleRate)
  const channels = buffers(2, length)
  const sampleRng = SampleRng.from(rng)
  const kit = KITS[score.kit] ?? KITS.acoustic

  for (const layer of score.layers) {
    const spec = kit[layer.instrument] ?? kit.perc
    const steps = layer.pattern.length * score.bars

    for (let step = 0; step < steps; step++) {
      const index = step % layer.pattern.length

      if (layer.pattern[index] !== 1) continue

      // A fresh noise burst per hit rather than one cached buffer reused. Two
      // identical snares in a row is the sound that gives a drum machine away,
      // and the burst is the cheapest part of the whole render.
      const hit = drum(spec, sampleRate, sampleRng)
      const at = Math.round(onsetSeconds(step, layer, score.swing) * sampleRate)

      if (at >= length) break

      pan(channels, hit, at, layer.pan, layer.gain * layer.accent[index])
    }
  }

  return channels
}
