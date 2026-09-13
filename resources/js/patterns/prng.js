/**
 * A sequential pseudo-random stream for the renderers that cannot afford the real
 * one.
 *
 * The canvas harness derives 8 KB from the render key, which is generous for a
 * permutation table and nowhere near enough for an algorithm that draws a number
 * per step. Bridson's sampler tries thirty candidate points around every accepted
 * one — two doubles each, fourteen bytes each — so a thousand points would want
 * something like two hundred kilobytes, and an L-system that rewrites a stochastic
 * rule a hundred thousand times wants more still.
 *
 * So the same trick the field renderers use for feature points applies here, one
 * dimension down: draw a single uint32 from the real stream and expand it locally.
 * The seed still comes from the entropy the receipt describes — nothing about the
 * provenance chain is weakened — and the expansion is deterministic, so the
 * browser and `scripts/render-preview.mjs` still draw the identical picture.
 *
 * splitmix32, because it is four lines, passes the usual statistical batteries at
 * this scale, and needs no state beyond one 32-bit word. This is decoration, not
 * cryptography; the guarantees that matter to this project live in the HKDF stream
 * that seeds it.
 */
export function splitmix32 (seed) {
  let state = seed >>> 0

  return function next () {
    state = (state + 0x9e3779b9) >>> 0
    let z = state
    z = Math.imul(z ^ (z >>> 16), 0x21f0aaad) >>> 0
    z = Math.imul(z ^ (z >>> 15), 0x735a2d97) >>> 0
    z = (z ^ (z >>> 15)) >>> 0

    return z / 4294967296
  }
}

/** An integer in [0, n), unbiased enough for drawing: n is always tiny here. */
export function below (random, n) {
  return Math.min(n - 1, Math.floor(random() * n))
}
