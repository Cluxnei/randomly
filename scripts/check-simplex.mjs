/**
 * Measure the three things `patterns.simplex` claims and Perlin does not give.
 *
 * The generator's whole argument is a comparison — a triangular lattice has no
 * preferred direction, a square one does — and an argument made only in prose is
 * an argument nothing can falsify. So the difference is measured here, against
 * the same `perlin2` the comparison mode draws with, and
 * tests/Unit/MeasuredPatternsTest.php asserts on what comes back.
 *
 * Four modes, because the four failures are unrelated:
 *
 *   range       OpenSimplex2's kernel radius and its normalising constant are a
 *               matched pair. Take r² = 2/3 with the r² = 1/2 constant and the
 *               field runs several times outside [−1, 1] — which a plain fBm
 *               hides, because paintField stretches whatever it is given, and
 *               which then silently wrecks `turbulence`, `ridged` and `billow`,
 *               since all three fold around fixed constants.
 *   continuity  The four branches in `simplex2` pick which further lattice
 *               points are within kernel range. A wrong branch drops a
 *               contribution on one side of a triangle edge and keeps it on the
 *               other, which is a step discontinuity — a hard crease along the
 *               lattice diagonals. Measured by scale rather than by absolute
 *               size: a continuous field's largest sample-to-sample jump falls
 *               with the sample spacing, a creased one's does not.
 *   isotropy    The headline. The gradient directions of the field are
 *               decomposed into angular harmonics; a square lattice with eight
 *               axis-and-diagonal gradients puts its energy into the 4-fold and
 *               8-fold terms, and those are exactly the terms an equilateral
 *               lattice has no reason to carry.
 *   match       Comparison mode draws Perlin at a higher frequency, because an
 *               OpenSimplex2 field at the same frequency is visibly finer. Lose
 *               that correction and the eye reads the two halves as "one side is
 *               finer" rather than "one side is square", which is not the claim
 *               being made. Measured off the rendered picture, so the constant
 *               under test is the one that ships.
 *
 *   node scripts/check-simplex.mjs <mode> <seed> [side]
 */
import { render, simplex2 } from '../resources/js/patterns/simplex.js'
import { perlin2 } from '../resources/js/patterns/perlin.js'
import { splitmix32 } from '../resources/js/patterns/prng.js'

const mode = process.argv[2]
const seed = Number(process.argv[3])
const side = Number(process.argv[4] ?? 400)

/**
 * A permutation table for the Perlin comparison.
 *
 * Shuffled here rather than taken from `Rng.permutation()` so the check needs no
 * HKDF stream to run. The table is only the source of "which gradient does this
 * lattice point hold"; nothing measured below depends on where those 256 numbers
 * came from, only that they are a permutation.
 */
function permutation () {
  const random = splitmix32(seed >>> 0)
  const p = [...Array(256).keys()]

  for (let i = 255; i > 0; i--) {
    const j = (random() * (i + 1)) | 0
    const t = p[i]
    p[i] = p[j]
    p[j] = t
  }

  return Uint8Array.from([...p, ...p])
}

const perm = permutation()
const simplex = (x, y) => simplex2(seed, x, y)
const perlin = (x, y) => perlin2(perm, x, y)

/**
 * Sample points, walked in irrational steps.
 *
 * A step that divides the lattice pitch would sample the same few positions
 * inside every cell over and over, which is the one sampling pattern guaranteed
 * to miss whatever happens between them.
 */
function* grid (step = 0.2137) {
  for (let i = 0; i < side; i++) {
    for (let j = 0; j < side; j++) {
      yield [10 + i * step, 10 + j * step * 0.997]
    }
  }
}

/** Largest absolute value the field reaches, and the extremes it reaches it at. */
function range (f) {
  let min = Infinity
  let max = -Infinity

  for (const [x, y] of grid(0.1379)) {
    const v = f(x, y)
    if (v < min) min = v
    if (v > max) max = v
  }

  return { min, max }
}

/** The largest jump between two samples `h` apart, over four directions. */
function maxJump (f, h) {
  let worst = 0

  for (const [x, y] of grid()) {
    const here = f(x, y)

    for (const [dx, dy] of [[h, 0], [0, h], [h, h], [h, -h]]) {
      const d = Math.abs(f(x + dx, y + dy) - here)
      if (d > worst) worst = d
    }
  }

  return worst
}

/**
 * Amplitude of the k-fold angular harmonic of the field's gradient directions.
 *
 * Every sample contributes its gradient angle weighted by the gradient's own
 * magnitude, so a flat region cannot vote. Normalised by the total weight, so
 * the number is a *share* — comparable between two fields of different
 * amplitude, which is the entire point of putting simplex and Perlin side by
 * side.
 *
 * A perfectly isotropic field gives zero for every k, up to sampling noise. The
 * k that matter here are 4 and 8: a square lattice repeats every 90°, and
 * Perlin's eight gradients sit every 45°.
 */
function harmonics (f, ks) {
  const cos = new Float64Array(ks.length)
  const sin = new Float64Array(ks.length)
  const h = 0.01
  let total = 0

  for (const [x, y] of grid()) {
    // Central differences: a one-sided difference measures the gradient half a
    // step away from where it is attributed, which smears the very alignment
    // being looked for.
    const gx = f(x + h, y) - f(x - h, y)
    const gy = f(x, y + h) - f(x, y - h)
    const magnitude = Math.hypot(gx, gy)

    if (magnitude < 1e-12) continue

    const theta = Math.atan2(gy, gx)
    total += magnitude

    for (let i = 0; i < ks.length; i++) {
      cos[i] += magnitude * Math.cos(ks[i] * theta)
      sin[i] += magnitude * Math.sin(ks[i] * theta)
    }
  }

  const out = {}

  for (let i = 0; i < ks.length; i++) {
    out[ks[i]] = Math.hypot(cos[i], sin[i]) / total
  }

  return out
}

/**
 * How often a field changes sign along a horizontal line, per line.
 *
 * A count of features per unit length, near enough — and the quantity
 * comparison mode has to equalise between its two halves.
 */
function signChanges (f, span, samples, lines) {
  let changes = 0

  for (let l = 0; l < lines; l++) {
    const y = 3.137 + l * 1.719
    let previous = f(0, y) < 0

    for (let i = 1; i <= samples; i++) {
      const now = f(i * span / samples, y) < 0
      if (now !== previous) changes++
      previous = now
    }
  }

  return changes / lines
}

/**
 * Feature density on each side of comparison mode, measured off the rendered
 * picture.
 *
 * Deliberately off the picture rather than off `simplex2` and `perlin2` with the
 * match applied by hand: the constant that equalises the two lives inside
 * `render`, and a check that applies its own copy of it would still pass after
 * the renderer's had been changed. A nine-line canvas shim is the price of
 * measuring the thing that actually ships.
 *
 * The count is of crossings of mid-grey rather than of zero. Each half is
 * stretched to its own [0, 1] before it is painted, so mid-grey is the middle of
 * each half's own range — the same criterion applied to both, which is all the
 * comparison needs.
 */
function comparisonDensity () {
  const width = 1600
  const height = 400
  let painted = null

  const ctx = {
    createImageData: (w, h) => ({ width: w, height: h, data: new Uint8ClampedArray(w * h * 4) }),
    putImageData: image => { painted = image },
  }

  render(ctx, {
    width,
    height,
    scale: 60,
    octaves: 1,
    persistence: 0.5,
    lacunarity: 2,
    variant: 'fbm',
    warp: 0,
    compare: true,
    contours: false,
    palette: ['#000000', '#ffffff'],
    seed,
    offset: [0, 0],
  }, { permutation: () => perm })

  const split = Math.floor(width / 2)

  // Four columns of margin each side of the seam, which is painted flat black
  // and would otherwise read as one very wide feature.
  const density = (from, to) => {
    let changes = 0

    for (let y = 0; y < height; y++) {
      let previous = painted.data[(y * width + from) * 4] >= 128

      for (let x = from + 1; x < to; x++) {
        const now = painted.data[(y * width + x) * 4] >= 128
        if (now !== previous) changes++
        previous = now
      }
    }

    return changes / height
  }

  return [density(4, split - 4), density(split + 4, width - 4)]
}

if (mode === 'range') {
  console.log(JSON.stringify(range(simplex)))
} else if (mode === 'continuity') {
  const coarse = 1 / 128
  const fine = coarse / 4

  console.log(JSON.stringify({
    spacing: coarse,
    jump_coarse: maxJump(simplex, coarse),
    jump_fine: maxJump(simplex, fine),
  }))
} else if (mode === 'isotropy') {
  const ks = [2, 4, 6, 8]

  console.log(JSON.stringify({
    simplex: harmonics(simplex, ks),
    perlin: harmonics(perlin, ks),
  }))
} else if (mode === 'match') {
  const [simplexHalf, perlinHalf] = comparisonDensity()

  // What the two halves would look like without the correction: the same two
  // fields sampled at the same frequency, from the exported noise functions.
  const bare = signChanges(simplex, 200, 200000, 40) / signChanges(perlin, 200, 200000, 40)

  console.log(JSON.stringify({
    simplex_density: simplexHalf,
    perlin_density: perlinHalf,
    ratio: perlinHalf ? simplexHalf / perlinHalf : null,
    uncorrected_ratio: bare,
  }))
} else {
  console.error(`unknown mode: ${mode}`)
  process.exit(1)
}
