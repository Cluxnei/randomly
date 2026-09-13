# Randomly — Specification

Read in order:

| Doc | Contents |
|---|---|
| [01 — Overview](01-overview.md) | What this is, positioning, product shape, non-goals |
| [02 — Architecture](02-architecture.md) | The generator contract, schema-driven UI, layout, request flow, testing policy |
| [03 — Entropy](03-entropy.md) | Real-world sources (probed live), HKDF conditioning, seeds, receipts, failure policy |
| [04 — Numbers](04-module-numbers.md) | Rng primitives, unbiased sampling, distributions, generators |
| [05 — Words](05-module-words.md) | Diceware, Markov chains, syllable grammars, word APIs |
| [06 — Equations](06-module-equations.md) | PCFG trees, backward construction, calculus, matrices, worksheets |
| [07 — Patterns](07-module-patterns.md) | Perlin/Simplex/Worley, fBm, domain warping, Gray–Scott, WFC, mazes |
| [08 — Images](08-module-images.md) | Flow fields, superformula, OKLCH palettes, identicons, museum APIs |
| [09 — Audio](09-module-audio.md) | Noise colours, scales, Euclidean rhythms, FM & Karplus–Strong, harmony |
| [10 — UI & Brand](10-ui-brand.md) | Visual direction, page-by-page layout, copy rules, shareables |
| [11 — API](11-api.md) | Public endpoints, receipt format, replay, errors, attribution |
| [12 — Roadmap](12-roadmap.md) | Build order, phase demos, risks, definition of done |

**Stack:** Laravel 13 · PHP 8.5 · Blade + Alpine 3 + Tailwind 4 · Pest (unit only) · no database at MVP.
