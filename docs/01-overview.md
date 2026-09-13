# Randomly — Overview

> **Randomness, sourced from reality.**

Randomly is a library of random generators. You pick what you want to be random —
numbers, words, equations, patterns, images, sound — and Randomly produces it from
**verifiable real-world entropy**: atmospheric noise, quantum vacuum fluctuations,
seismic events, Bitcoin block hashes, space weather.

Every result ships with a **Seed Receipt**: where the randomness came from, when,
and a link to the third-party proof. Every result is **reproducible** from its URL.

---

## 1. Why this exists (the positioning)

Most "random" tools call `rand()` and move on. Randomly makes the *source* of the
randomness the product. That is the entire marketing hook:

| Generic tool | Randomly |
|---|---|
| "Here's a random number." | "Here's a number derived from NIST Beacon pulse #1937618, signed and timestamped 42s ago." |
| Output disappears on refresh | Output has a permalink and replays bit-for-bit |
| Black box | Receipt with provenance URL you can verify yourself |

The tagline family:

- `Randomness, sourced from reality.`
- `Every result has a receipt.`
- `Entropy you can point at.`

## 2. Product shape

A **library page** (the catalogue) listing every generator as a live, animated card.
Click one → a **studio page** with controls on the left, the result big on the right,
and the receipt underneath. Copy, download, or share the permalink.

```
/                        landing — hero, live entropy ticker, featured generators
/library                 the catalogue (filter by module, by entropy source)
/g/{module}/{generator}  the studio for one generator
/r/{token}               permalink — replays an exact past result
/api/v1/...              the same generators as JSON
/entropy                 live dashboard of every entropy source
```

## 3. Modules at launch

| Module | Generates | Core techniques |
|---|---|---|
| **Numbers** | integers, floats, distributions, dice, UUIDs, lottery picks, coordinates | rejection sampling, Box–Muller, alias method, Fisher–Yates |
| **Words** | passphrases, pseudo-words, names, titles, lorem | Diceware, order-n Markov chains, syllable grammars, Datamuse |
| **Equations** | arithmetic, algebra, calculus, matrices, identities | PCFG expression trees, root-first construction, KaTeX |
| **Patterns** | Perlin/Simplex/Worley noise, fBm, cellular automata, reaction–diffusion, mazes | gradient noise, Gray–Scott PDE, Wave Function Collapse |
| **Images** | flow fields, circle packing, identicons, palettes, public-domain art | superformula, golden-angle hue rotation, Poisson-disk |
| **Audio** | noise colors, melodies, rhythms, plucked strings, drones | Karplus–Strong, FM synthesis, Euclidean rhythms, ADSR |

Each module is specced in its own document (`04-` through `09-`).

## 4. Non-goals

- **No user accounts.** Nothing to sign up for. Sharing is a URL.
- **No database at MVP.** Reproducibility lives in the seed, not in storage.
- **No paid APIs, ever.** Every external source is free and keyless (see `03-entropy.md`).
- **No heavy test suite.** This is a showcase. Cheap unit tests only — determinism,
  distribution sanity, and known-answer vectors. No browser tests, no API integration tests.
- **No SPA.** Blade renders, Alpine animates, `fetch()` refreshes. That's the whole stack.

## 5. Guiding constraints

1. **Never block on a third party.** Every external source has a 1.5s timeout and
   falls back to the OS CSPRNG, with the receipt honestly marked `degraded`.
2. **Never reuse a seed.** Cached external entropy is always mixed with fresh local
   entropy before use (see `03-entropy.md` §5).
3. **Server generates the seed, client renders the pixels.** Canvas and Web Audio do
   the heavy work; PHP stays fast and stateless.
4. **One contract, many generators.** Adding a generator is one class. The UI, the API,
   and the catalogue all build themselves from it (see `02-architecture.md`).
