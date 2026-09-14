/**
 * Life-like cellular automata, in B/S notation.
 *
 *   next(c) = alive(c) ? survive[n(c)] : birth[n(c)]
 *
 * where n(c) is the count of the eight neighbours that are alive. Conway's rule
 * is B3/S23; the whole family is the 2¹⁸ ways of filling those two tables, and
 * four of them are worth a name.
 *
 * **What is drawn is not the last generation.** A Conway soup settles after a few
 * hundred steps into scattered still lifes and blinkers on an empty field, which
 * is a true picture of the rule and a bad picture of anything. So the default
 * render is a long exposure: every cell keeps a decaying accumulator of how often
 * it has been alive, and the image is that field. Still lifes burn in bright,
 * oscillators sit half as bright, gliders leave comet trails, and the regions the
 * soup died in early stay dark — so the picture shows the *history* of the run
 * rather than one frame of it, which is where all of the structure is.
 */
import { hexToRgb, paintField, hash2 } from './shared.js'

/**
 * How much of the accumulator survives each generation.
 *
 *   heat ← heat · decay + alive
 *
 * so a permanently live cell converges on 1/(1−decay) and a cell that fires once
 * fades to a tenth of that in about eighteen generations. 0.88 puts the trail
 * length in the same range as a glider's period, which is what makes gliders read
 * as moving objects rather than as dotted lines.
 */
const DECAY = 0.88

/**
 * One generation, on a torus.
 *
 * Exported for scripts/check-life.mjs, which runs the known still lifes and
 * oscillators through this exact function. A second implementation written for
 * the test would agree with itself and prove nothing.
 *
 * The neighbour count is a plain nine-way gather rather than anything clever.
 * The wrapping is done with a conditional per axis instead of a modulo: the
 * modulo version is three times slower here, and this is the only hot loop.
 */
export function step (state, next, cols, rows, birth, survive) {
  for (let y = 0; y < rows; y++) {
    const up = (y === 0 ? rows - 1 : y - 1) * cols
    const mid = y * cols
    const down = (y === rows - 1 ? 0 : y + 1) * cols

    for (let x = 0; x < cols; x++) {
      const left = x === 0 ? cols - 1 : x - 1
      const right = x === cols - 1 ? 0 : x + 1

      const n = state[up + left] + state[up + x] + state[up + right]
        + state[mid + left] + state[mid + right]
        + state[down + left] + state[down + x] + state[down + right]

      next[mid + x] = state[mid + x] ? survive[n] : birth[n]
    }
  }

  return next
}

export function render (ctx, spec) {
  const { width, height, cell, cols, rows, generations, density, birth, survive, palette } = spec
  const seed = spec.seed >>> 0

  let state = new Uint8Array(cols * rows)
  let next = new Uint8Array(cols * rows)

  // Hashed rather than drawn from the stream: a 300×200 grid is sixty thousand
  // coin flips and the render stream is 8 KB. The seed still comes from the
  // entropy the receipt describes — see patterns/prng.js for the full argument.
  for (let i = 0; i < state.length; i++) {
    state[i] = hash2(seed, i % cols, (i / cols) | 0) / 4294967296 < density ? 1 : 0
  }

  const heat = new Float32Array(cols * rows)

  for (let g = 0; g < generations; g++) {
    for (let i = 0; i < heat.length; i++) {
      heat[i] = heat[i] * DECAY + state[i]
    }

    step(state, next, cols, rows, birth, survive)

    const swap = state
    state = next
    next = swap
  }

  /*
   * The final generation is folded into the exposure at full weight.
   *
   * Without it a run that ends mid-oscillation looks identical to one that ended
   * dead, and the still lifes — the things a Life picture is actually about —
   * carry no more weight than the last glider that passed through. The constant
   * is 1/(1−decay), the same value a permanently live cell converges on, so a
   * still life ends at twice the brightness of anything transient.
   */
  const boost = 1 / (1 - DECAY)
  for (let i = 0; i < heat.length; i++) {
    /*
     * log(1 + heat), for the same reason film is logarithmic.
     *
     * The accumulator is heavily tailed: a still life ends at 16.7 while the
     * comet trail of a glider that passed through once is under 1. Stretched
     * linearly, everything except the still lifes lands in the first twentieth
     * of the ramp and the picture is a scatter of bright dots on black — which
     * is what the first render of this actually looked like. Compressing the
     * top end is what makes the ash of a died-out region a visible colour
     * rather than a rounding error.
     */
    heat[i] = Math.log1p(heat[i] + state[i] * boost)
  }

  const colours = palette.map(hexToRgb)
  const field = new Float32Array(width * height)

  /*
   * Expand cells to pixels.
   *
   * One row of cells is written once and then copied down for the remaining
   * cell-height scanlines, the same trick automaton.js uses: at cell = 6 that is
   * one pass of index arithmetic and five typed-array copies instead of six
   * passes, and the engine does the copies at memory speed.
   */
  for (let cy = 0; cy < rows; cy++) {
    const top = cy * cell
    if (top >= height) break

    for (let cx = 0; cx < cols; cx++) {
      const value = spec.mode === 'state' ? state[cy * cols + cx] : heat[cy * cols + cx]
      const x0 = cx * cell

      for (let dx = 0; dx < cell && x0 + dx < width; dx++) {
        field[top * width + x0 + dx] = value
      }
    }

    const row = field.subarray(top * width, top * width + width)
    for (let dy = 1; dy < cell && top + dy < height; dy++) {
      field.set(row, (top + dy) * width)
    }
  }

  paintField(ctx, field, width, height, colours, { contours: spec.mode === 'state' })
}
