# Build Order

Sequenced so there is something demoable after every phase, and so the riskiest piece
(entropy) is proven before anything is built on top of it.

## Phase 0 — Foundation ✅ done

- `sudo pacman -S php php-gd php-sqlite composer` (PHP 8.5.9; gd/sqlite3/pdo_sqlite/intl/
  bcmath/exif enabled via `/etc/php/conf.d/randomly.ini`)
- `composer create-project laravel/laravel .` — landed on **Laravel 13.31**, which ships
  Tailwind 4 and Vite 8 in the skeleton already
- Tailwind 4 + Vite, Alpine 3, JetBrains Mono + Inter, base layout, dark palette tokens
- `app/Random/` skeleton: `Generator`, `ParamSchema`, `Params`, `Result`, `Registry`

**Demo:** an empty but beautiful shell.

## Phase 1 — Entropy core ✅ done ← the load-bearing phase

- `Rng`, `HkdfStream`, `Seed`, `Receipt`, `EntropyPool`
- Sources: `Csprng`, `RandomOrg`, `NistBeacon`, `Drand` (the four Class A / high-value ones)
- Circuit breaker + cache + degradation
- Unit tests: HKDF RFC 5869 vectors, determinism, `intBetween` bias χ²

**Demo:** `php artisan randomly:seed --source=nist` prints a seed and its receipt in
the terminal. Unglamorous and the most important moment of the build.

## Phase 2 — First vertical slice ✅ done

- `numbers.integers` + `numbers.dice` + `numbers.password`
- `ParamSchema` → `<x-control>` auto-rendered panel
- `/library`, `/g/{module}/{generator}`, `POST /api/v1/generate`, `/r/{token}`
- Receipt card component

**Demo:** the full loop works end to end for one module. Everything after this is
filling in the registry.

## Phase 3 — Visual modules ✅ done

- `resources/js/rng.js` — the client-side HKDF stream matching the server's
- Canvas harness: seed in, pixels out, PNG export
- `patterns.perlin` → `fbm` → `warp` → `worley` → `reaction` → `automaton`
- `images.flowfield`, `images.blob`, `images.identicon`, the palette engine (OKLCH)

**Demo:** the site starts looking like the pitch. This is when it becomes screenshottable.

## Phase 4 — Words & Equations ✅ done

- EFF wordlists bundled; Diceware with live entropy readout
- Markov + syllable generators
- AST evaluator, PCFG forward generation, backward construction for linear/quadratic
- KaTeX rendering, worksheet print view

## Phase 5 — Audio ✅ done

- Web Audio harness, score format, master limiter
- `audio.noise` (Voss–McCartney) → `audio.rhythm` (Euclidean) → `audio.melody` →
  `audio.pluck` (Karplus–Strong)
- Offline render → WAV export

## Phase 6 — The marketing surface ✅ done

- Landing page: hero canvas, live entropy ticker, how-it-works SVG
- `/entropy` dashboard
- OG image baking (GD) for permalinks
- Remaining external sources: `Bitcoin`, `Seismic`, `SpaceWeather`, `Atmosphere`, `Iss`, `AnuQrng`
- Credits page

## Phase 7 — Fill out the registry ✅ done — all 63 shipped

Every generator from `04`–`09` is written. The architecture's claim was that adding one
would be a class plus a registry line, needing no route, controller or view — and 31
generators were added across three batches without any of those three files being touched
once. That is the strongest evidence the schema-driven design works.

The roadmap file is now empty, which the landing page and the library both handle: a
"Planned" heading over an empty grid reads as a rendering fault rather than an
achievement, so it is hidden, and "0 more specified" became "every one specified is
built".

---

## Risks

| Risk | Mitigation |
|---|---|
| A free API disappears or starts requiring a key | Every source is optional by construction; the pool degrades to CSPRNG. Losing one costs a card on `/entropy`, not a feature |
| random.org daily quota exhausted | Marked `exhausted`, hidden from the picker until midnight UTC |
| Client/server RNG streams diverge | A dedicated test: PHP and Node produce identical first 64 bytes for a fixed seed. Run in CI |
| WFC / DLA are slow in the browser | Cap grid sizes; run in a Web Worker; show progress |
| Scope creep across 50+ generators | Phases 0–6 are the product. Phase 7 is optional content, shippable one generator at a time |

## Definition of done (for the showcase)

- Every one of the six modules has **at least three** working generators
- Every result has a receipt with a live proof link
- Every permalink replays exactly
- The landing page loads in under 1.5s and animates at 60fps
- `curl` one-liner works, unauthenticated, and returns something beautiful
