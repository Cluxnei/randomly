# Module — Images

Where Patterns makes texture, Images makes **compositions** — things that look composed
rather than sampled. Plus the two "real image" generators that pull genuine photographs
and public-domain art.

## 1. Procedural generators

| Key | Technique | Core math |
|---|---|---|
| `images.flowfield` | particles advected through a noise field | `θ(x,y) = fBm(x·s, y·s)·2π`, `p ← p + (cos θ, sin θ)·step` |
| `images.circles` | circle packing | draw candidate, keep if it fits, grow until collision |
| `images.mondrian` | recursive subdivision | split rectangle at `U(0.3, 0.7)`, recurse while `area > t`, fill some cells |
| `images.blob` | superformula | see §2 |
| `images.gradient` | multi-stop mesh gradient | random control points, bilinear blend, noise dither to kill banding |
| `images.identicon` | deterministic avatar from a hash | see §4 |
| `images.spray` | weighted particle spray | position from a 2D Gaussian mixture with k random components |
| `images.tiles` | generative tile grid | random glyph + rotation per cell, from a bundled glyph set |
| `images.strata` | horizontal band layers | band heights from a Dirichlet draw, colours walk a palette |

Everything renders on `<canvas>`; PNG export via `canvas.toBlob()`. Server-side GD is
used only to bake the Open Graph preview image for a permalink — social cards matter for
a marketing project and are worth the one extra code path.

## 2. Superformula

One equation, an enormous family of shapes:

```
r(φ) = ( |cos(mφ/4)/a|^n₂ + |sin(mφ/4)/b|^n₃ )^(−1/n₁)
```

Sample `φ ∈ [0, 2π)`, convert to Cartesian. `a = b = 1`. Extreme parameter values look
like starfish, gears, leaves, flowers and sea urchins, and a "surprise me" button that
redraws every parameter is the entire interaction.

### 2.1 Odd `m` does not close unless `n₂ = n₃`

Found while building it, and not obvious from the formula. Write the argument as
`x = mφ/4`. Both `|cos x|` and `|sin x|` have period **π**, so the whole bracket does
too — but advancing `φ` by a full turn advances `x` by `mπ/2`.

- **`m` even** → `mπ/2` is a whole multiple of `π`. The curve closes for any `n₂, n₃`.
- **`m` odd** → `mπ/2` is an *odd* multiple of `π/2`, and shifting by `π/2` swaps
  `|cos|` with `|sin|`. So `r(2π) ≠ r(0)` unless **`n₂ = n₃`**, and the shape ends with
  a hard radial seam where the sampling wraps.

Half of all `m` values are odd, so drawing `n₂` and `n₃` independently puts a seam
through roughly half of every plate. Tie `n₃ = n₂` whenever `m` is odd; even `m` may
still split them, which is where the alternating-lobe gear family comes from.

### 2.2 Draw ranges that actually produce shapes

Uniform over the full published ranges spends most of its draws on visual mush. What
ships instead, with reasons:

| Parameter | Range | Why |
|---|---|---|
| `m` | integer `[3, 14]`, slider to 16 | non-integer `m` never closes at all |
| `n₁` | `[0.45, 5.65]`, biased low via `float()²` | uniform over `[0.3, 8]` lands almost everything at the round end |
| `n₂, n₃` | ratio between them clamped to `[1.15, 8.0]` | above ~8 the lobes become hairline spikes; below 1.15 you get a circle, and a surprise-me button that returns a circle has surprised nobody |
| `aspect` | `[0.74, 1.30]` | where the leaf and shell forms live |

### 2.3 Composition beats parameters

The first build drew three concentric shapes in the middle of an empty frame with eight
translucent rings and a twist, and the result read as scribble — the maths was right and
the picture was bad. What fixed it was layout, not formula: one dominant specimen at
0.52–0.68 of the short side always bleeding off at least two edges, companions placed
polar about an already-placed shape at a distance set from both radii so they touch
rather than interpenetrate, and five opaque bands instead of eight translucent ones. The
spirograph is still reachable on the sliders; it just is not the default.

## 3. Colour — the palette engine

Shared by every visual generator. Three strategies:

### 3.1 Golden-angle hue rotation

Maximally spread hues without clustering:

```
hᵢ₊₁ = (hᵢ + 0.618033988749895) mod 1      (the golden ratio conjugate)
```

Starting hue is random; saturation and lightness jitter within a narrow band. Produces
palettes that never collide, at any length.

### 3.2 Harmony rules

Pick a base hue `h`, then:

| Harmony | Hues |
|---|---|
| Complementary | `h`, `h+180°` |
| Triadic | `h`, `h+120°`, `h+240°` |
| Analogous | `h−30°`, `h`, `h+30°` |
| Split-complementary | `h`, `h+150°`, `h+210°` |
| Tetradic | `h`, `h+90°`, `h+180°`, `h+270°` |

### 3.3 Perceptual uniformity — use OKLCH, not HSL

HSL lies: `hsl(60, 100%, 50%)` (yellow) and `hsl(240, 100%, 50%)` (blue) claim identical
lightness and are wildly different to the eye. Generate in **OKLCH** instead, where `L`
is perceptual, then convert to sRGB and gamut-clamp by reducing chroma until the colour
is representable:

```
while (!inSrgbGamut(L, C, H)) C -= 0.005
```

Tailwind 4 already speaks `oklch()` natively, so palettes drop straight into CSS custom
properties with no conversion layer. Contrast pairs are checked against WCAG APCA so
generated palettes are actually usable in a UI — which makes
`images.palette` genuinely useful to designers, not just pretty.

**Bundled curated ramps** as an alternative to fully random: viridis, magma, cividis
(perceptually uniform, colour-blind safe), plus a few hand-picked art palettes.

## 4. Identicons

Deterministic avatar from any string. Hash the input, then read the bytes as a design:

```
digest = sha256(input)
hue     = digest[0] / 255                     → colour via OKLCH
grid    = 5×5, mirrored on the vertical axis  → only 15 bits needed
cell(i) = bit i of digest[1..2]               → on/off
shape   = digest[3] % shapeCount              → square, circle, triangle, diamond
```

Mirroring is what makes it read as a *face* rather than static. Same input ⇒ same
identicon, forever, with no storage — the module's clearest demonstration of
determinism.

## 5. Real images (external, all probed working)

| Key | Source | Endpoint | Notes |
|---|---|---|---|
| `images.photo` | Lorem Picsum | `picsum.photos/seed/{seed}/{w}/{h}` + `/id/{id}/info` | Unsplash-backed, seeded ⇒ **reproducible**, free, keyless, photographer credited from `/info` |
| `images.artwork` | The Met | `collectionapi.metmuseum.org/public/collection/v1/objects` → `/objects/{id}` | ~490k objects; filter `isPublicDomain` and `primaryImage != ""`; CC0 |
| `images.artic` | Art Institute of Chicago | `api.artic.edu/api/v1/artworks?page=…&limit=1` | IIIF image URLs, rich metadata, public domain filter |

The Met's `/objects` endpoint returns the full ID list (2 644 IDs for a single
department) — cache the list for 24h and pick from it locally, rather than re-fetching
100 KB per generation. Many objects lack images, so retry up to 5 times before
falling back to a procedural generator with an honest note in the receipt.

`images.artwork` is the strongest marketing surface in the whole project: *"A random
masterpiece, chosen by an earthquake."* Real art, real entropy, real provenance —
all three receipts on one card.

## 6. Composition params

Shared across procedural generators: `width` (256–2048), `height`, `palette`
(strategy + count), `background` (light/dark/from-palette), `grain` (film-grain overlay
opacity), `margin`, `export`.

Grain deserves a mention: adding `±2%` per-pixel noise over a flat render is a two-line
change that makes generative output look intentional instead of computer-made. On by
default at low strength.
