# Module — Patterns (Noise & Structure)

The visual heart of the project. Server emits `{algorithm, params, seed}`; the browser
draws it on `<canvas>` using the *same* HKDF stream, so client and server agree bit for
bit. Re-rendering on a slider drag is instant and needs no round trip.

## 1. Gradient noise

### 1.1 Value noise (the warm-up)

Hash lattice corners to random values, interpolate with a smoothstep:

```
fade(t) = 6t⁵ − 15t⁴ + 10t³        (quintic — C² continuous, no visible creases)
n(x,y)  = lerp( lerp(v₀₀,v₁₀,fade(fx)), lerp(v₀₁,v₁₁,fade(fx)), fade(fy) )
```

### 1.2 Perlin noise

Corners hold *gradients*, not values; interpolate dot products:

```
n(p) = Σ_corners  w(p − c) · ( g(c) · (p − c) )
```

with `g(c)` drawn from the 8 (2D) or 12 (3D) canonical gradient vectors, indexed by a
seeded permutation table of 256 entries duplicated to 512. `n(p) = 0` at every lattice
point — that's our known-answer test.

### 1.3 Simplex / OpenSimplex

Perlin on a simplex grid: O(n²) instead of O(2ⁿ), no axis-aligned artefacts. In 2D the
grid is skewed by

```
F₂ = (√3 − 1)/2        G₂ = (3 − √3)/6
```

Use **OpenSimplex2** to sidestep the Perlin-simplex patent lineage entirely (expired in
2022, but OpenSimplex is cleaner and looks better anyway).

### 1.4 Worley / cellular noise

Scatter feature points per grid cell, take distances:

```
F₁ = distance to nearest point
F₂ = distance to second nearest
F₂ − F₁  → the classic cracked-cell / Voronoi-edge look
```

Distance metric is a parameter: Euclidean, Manhattan (blocky), Chebyshev (square cells),
Minkowski-p (a slider between them). Changing the metric live is a great demo.

> **Shipped as one generator, not three.** `patterns.perlin` carries octaves, the
> four fBm variants below, and domain warping as sliders. One octave with warp at
> zero *is* plain Perlin; nine octaves with warp at 1.0 is a different planet. They
> are the same code path, and splitting them into three catalogue entries would
> have been padding the count. `patterns.fbm` and `patterns.warp` are therefore
> gone from the roadmap rather than pending.

## 2. Fractal composition (fBm)

Stack octaves of any base noise:

```
fBm(p) = Σᵢ₌₀^(O−1)  Aᵢ · noise(fᵢ · p)
    fᵢ = lacunarity^i      (default 2.0)
    Aᵢ = persistence^i     (default 0.5)
```

Variants, all one-line changes and all visually distinct:

| Variant | Formula | Look |
|---|---|---|
| fBm | `Σ Aᵢ n(fᵢp)` | clouds, terrain |
| **Turbulence** | `Σ Aᵢ |n(fᵢp)|` | smoke, fire |
| **Ridged** | `Σ Aᵢ (1 − |n(fᵢp)|)²` | mountain ridges |
| **Billow** | `Σ Aᵢ (2|n(fᵢp)| − 1)` | puffy clouds |

## 3. Domain warping

The single highest-value-per-line technique in the module:

```
q(p) = ( fBm(p + o₁), fBm(p + o₂) )
r(p) = ( fBm(p + 4q + o₃), fBm(p + 4q + o₄) )
out  = fBm(p + 4r)
```

Each nesting level makes the output dramatically more organic. Expose `warp strength`
and `warp octaves` as sliders — users will play with this one for minutes.

## 4. Spectral noise (1/f^β)

Generate in the frequency domain and inverse-FFT:

```
|F(f)| ∝ f^(−β/2),  phase ~ U(0, 2π)
β = 0 → white   β = 1 → pink   β = 2 → brown/red   β = −1 → blue
```

Shares an implementation with the audio module's noise colours (`09-module-audio.md` §2) —
the same spectral shaping, one dimension apart. Nice cross-module story to tell.

## 5. Reaction–diffusion (Gray–Scott)

Two chemicals on a grid, integrated with an explicit Euler step:

```
∂u/∂t = Dᵤ∇²u − u v² + F(1 − u)
∂v/∂t = Dᵥ∇²v + u v² − (F + k)v
```

`∇²` via the 9-point Laplacian kernel `[[.05,.2,.05],[.2,−1,.2],[.05,.2,.05]]`.
Defaults `Dᵤ=0.16, Dᵥ=0.08, Δt=1`. The `(F, k)` plane is the whole personality:

| F | k | Pattern |
|---|---|---|
| 0.035 | 0.065 | mitosis — dividing cells |
| 0.055 | 0.062 | coral growth |
| 0.030 | 0.062 | solitons |
| 0.025 | **0.055** | maze-like labyrinth |
| 0.014 | 0.054 | pulsating spots |

**Corrected after building it.** This table originally listed the labyrinth at
`k = 0.050`, which is wrong: at F=0.025 that pair decays to a near-uniform field and stays
there through 30,000 steps, from any seeding. A k sweep puts the stripe regime at 0.055,
which is where Pearson's published diagram puts it too — so the original was a
transcription slip. Rendered side by side, 0.050 is a smudge and 0.055 is the labyrinth.
The other four pairs are correct as published.

**Diffusion rates.** Ship `Dᵤ=1.0, Dᵥ=0.5` rather than the 0.16/0.08 often quoted. Same
2:1 ratio, clock simply run faster — at 0.16 the patterns do form, they just need around
60,000 steps, which is not a slider anyone will wait out. The ceiling is `D·Δt = 1.25`:
the 9-point Laplacian's most negative Fourier eigenvalue is −1.6, and explicit Euler
diverges past it. Cap the control below that rather than letting a drag blow the
simulation up.

Ship these as named presets **and** a draggable 2D pad over the (F,k) plane, with the
random seed setting the initial perturbation. Budget the work rather than the frame rate:
`grid² × steps ≤ 140M` keeps a drag under about a second.

## 6. Cellular automata

- **Elementary (1D)**: rule number 0–255, each row from the one above. Rule 30 is
  chaotic (and was Mathematica's RNG); rule 110 is Turing-complete; rule 90 is
  Sierpiński. Random seed = random first row, or a single centre cell.
- **Life-like (2D)**: B/S notation — `B3/S23` Conway, `B36/S23` HighLife,
  `B1357/S1357` Replicator, `B234/S` Serviettes. Random initial density is a slider.

## 7. Structured randomness

| Generator | Algorithm | Note |
|---|---|---|
| **Poisson-disk** | Bridson's algorithm, O(n) | blue-noise points, no two closer than `r`. Compared side-by-side with uniform random points — the visual difference *is* the lesson |
| **Voronoi / Delaunay** | Fortune's sweepline or Delaunator | cells coloured by area or by a noise field |
| **Mazes** | randomised DFS (long corridors), Kruskal (uniform), Wilson's (loop-erased random walk = *uniform spanning tree*, provably unbiased) | all three side by side shows how algorithm choice biases "random" |
| **Wave Function Collapse** | overlapping model, min-entropy heuristic + backtracking | a bundled tileset; the most impressive thing on the site |
| **L-systems** | axiom + rewrite rules, stochastic productions | trees, ferns, Koch/dragon curves |
| **Truchet tiles** | random tile orientation from a small set | instant beauty, ten lines of code |
| **Random walks** | Brownian, self-avoiding, Lévy flight (`step ~ Pareto(α)`) | Lévy's fat tail produces foraging-like paths |
| **DLA** | diffusion-limited aggregation | grows dendritic crystals; slow but mesmerising |

## 8. Generators list

`patterns.perlin` · `patterns.simplex` · `patterns.worley` · `patterns.fbm` ·
`patterns.warp` · `patterns.spectral` · `patterns.reaction` · `patterns.automaton` ·
`patterns.life` · `patterns.poisson` · `patterns.voronoi` · `patterns.maze` ·
`patterns.wfc` · `patterns.lsystem` · `patterns.truchet` · `patterns.walk` · `patterns.dla`

Shared params across all of them: `width`, `height`, `palette`, `seed`, `export` (PNG /
SVG where meaningful). Palettes come from the images module (`08-module-images.md` §3).

## 9. Two implementation rules, learned the hard way

Both of these were bugs in the first Perlin build, and both are invisible until you
look at the output.

**Colour a field with a ramp, never with a categorical palette.** `Palette::build()`
returns maximally-distinguishable colours for marking unrelated things; golden-angle
hues land 222° apart. A noise field is a *height map*, so interpolating between two
such colours crosses the middle of the colour wheel and lands in mud. `Palette::ramp()`
moves monotonically in lightness across a narrow arc of hue, with chroma following
`sin(πt)` — pinned low at both ends, fullest in the middle, the shape viridis uses. Every
interpolated value is then a colour somebody would have chosen.

**Normalise in two passes.** No fixed remap fits every variant: `ridged` lands in [0,1]
while `fbm` spans [-1,1], and stacking octaves pulls values towards the centre — the more
octaves, the tighter. Measure the field's real min/max, then stretch. One extra pass over
an array already in cache is far cheaper than re-evaluating the noise.

A trap inside that second rule: the field is a `Float32Array`, so **read values back out
before comparing them**. Storing rounds a double to float32, and a double compared
beforehand can sit a hair outside the range the stored floats occupy. A `t` of `-1e-7`
floors to index `-1`, and `colours[-1]` is `undefined`.
