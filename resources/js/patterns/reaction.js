/**
 * Gray–Scott reaction–diffusion.
 *
 *   u' = u + Dᵤ∇²u − uv² + F(1 − u)
 *   v' = v + Dᵥ∇²v + uv² − (F + k)v
 *
 * Explicit Euler at Δt = 1, with the 9-point Laplacian
 *
 *   [ .05 .2 .05 ]
 *   [ .2  −1 .2  ]
 *   [ .05 .2 .05 ]
 *
 * The diagonal weights are what stop the grid from showing through. A 5-point
 * stencil is cheaper and leaves every spot subtly square, aligned to the axes —
 * the one artefact in this whole module that a viewer notices without being able
 * to name.
 */
import { hashFloat, hexToRgb, paintField } from './shared.js'

export function render (ctx, spec, rng) {
  const { width, height, grid, steps, feed, kill, du, dv, palette, contours, invert } = spec

  const n = grid
  const size = n * n
  const noiseSeed = spec.noise_seed >>> 0

  let u = new Float32Array(size).fill(1)
  let v = new Float32Array(size)
  let un = new Float32Array(size)
  let vn = new Float32Array(size)

  /*
   * Seed the disturbance. u is knocked down and v raised inside each patch, which
   * is the standard Gray–Scott kick: v cannot grow anywhere it is exactly zero,
   * because every term that creates it is multiplied by v.
   *
   * The per-cell jitter matters more than it looks. A patch seeded to a flat value
   * is perfectly symmetric, and several of the presets will hold that symmetry for
   * thousands of steps and hand back a grid of identical circles. A hair of noise
   * breaks it and the pattern grows the way the real thing does.
   */
  for (const [sx, sy, sr] of spec.seeds) {
    const cx = sx * n
    const cy = sy * n
    const r = Math.max(1.5, sr * n)
    const r2 = r * r

    const lo = Math.max(0, Math.floor(cy - r))
    const hi = Math.min(n - 1, Math.ceil(cy + r))

    for (let y = lo; y <= hi; y++) {
      for (let x = Math.max(0, Math.floor(cx - r)); x <= Math.min(n - 1, Math.ceil(cx + r)); x++) {
        const dx = x - cx
        const dy = y - cy
        if (dx * dx + dy * dy > r2) continue

        const i = y * n + x
        const jitter = hashFloat(noiseSeed, x, y) * 0.06 - 0.03
        u[i] = 0.5 + jitter
        v[i] = 0.25 + jitter
      }
    }
  }

  for (let step = 0; step < steps; step++) {
    for (let y = 0; y < n; y++) {
      /*
       * The grid is a torus. Wrapping is resolved once per row for the vertical
       * neighbours and by the two edge branches below for the horizontal ones,
       * rather than with a modulo in the inner loop — a modulo per neighbour is
       * eight integer divisions per cell, and this loop runs a hundred million
       * times.
       */
      const rowUp = (y === 0 ? n - 1 : y - 1) * n
      const row = y * n
      const rowDown = (y === n - 1 ? 0 : y + 1) * n

      for (let x = 0; x < n; x++) {
        const xl = x === 0 ? n - 1 : x - 1
        const xr = x === n - 1 ? 0 : x + 1

        // The nine indices are named once rather than recomputed inside the two
        // stencils: this is the hottest arithmetic in the module, and halving the
        // index maths is worth roughly a fifth of the render.
        const nw = rowUp + xl; const nn = rowUp + x; const ne = rowUp + xr
        const ww = row + xl; const i = row + x; const ee = row + xr
        const sw = rowDown + xl; const ss = rowDown + x; const se = rowDown + xr

        const ui = u[i]
        const vi = v[i]

        const lu = 0.2 * (u[ww] + u[ee] + u[nn] + u[ss]) +
          0.05 * (u[nw] + u[ne] + u[sw] + u[se]) - ui

        const lv = 0.2 * (v[ww] + v[ee] + v[nn] + v[ss]) +
          0.05 * (v[nw] + v[ne] + v[sw] + v[se]) - vi

        const uvv = ui * vi * vi

        un[i] = ui + du * lu - uvv + feed * (1 - ui)
        vn[i] = vi + dv * lv + uvv - (feed + kill) * vi
      }
    }

    let swap = u; u = un; un = swap
    swap = v; v = vn; vn = swap
  }

  /*
   * Sample the simulation up to the canvas bilinearly.
   *
   * Nearest-neighbour would be faster and would put a 4-pixel staircase on every
   * edge of a 224² grid stretched to 900 across; the pattern's whole appeal is
   * that its boundaries are smooth curves, so it is worth four lerps a pixel to
   * keep them.
   */
  const field = new Float32Array(width * height)
  const sx = n / width
  const sy = n / height

  let f = 0
  for (let y = 0; y < height; y++) {
    const gy = y * sy
    const y0 = Math.floor(gy)
    const y1 = y0 + 1 >= n ? 0 : y0 + 1
    const ty = gy - y0
    const r0 = y0 * n
    const r1 = y1 * n

    for (let x = 0; x < width; x++) {
      const gx = x * sx
      const x0 = Math.floor(gx)
      const x1 = x0 + 1 >= n ? 0 : x0 + 1
      const tx = gx - x0

      const top = v[r0 + x0] + (v[r0 + x1] - v[r0 + x0]) * tx
      const bottom = v[r1 + x0] + (v[r1 + x1] - v[r1 + x0]) * tx

      field[f++] = top + (bottom - top) * ty
    }
  }

  paintField(ctx, field, width, height, palette.map(hexToRgb), { contours, invert })
}
