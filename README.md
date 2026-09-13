<h1 align="center">Randomly</h1>
<p align="center"><strong>Randomness, sourced from reality.</strong></p>
<p align="center">
  A library of random generators — numbers, words, equations, patterns, images, sound —<br>
  seeded by atmospheric noise, quantum vacuum fluctuations, earthquakes and proof-of-work.<br>
  Every result ships with a receipt. Every result is reproducible from its URL.
</p>

---

## What it does

You pick what you want to be random. Randomly generates it from **verifiable
real-world entropy** and hands you the provenance alongside the result:

> `7, 13, 24, 31, 45, 58`
> *Derived from a magnitude 4.2 earthquake, 31 km off the coast of Honshu, 6 minutes ago.* ↗

Six modules at launch:

| Module | Generates |
|---|---|
| **Numbers** | integers, distributions, dice notation, UUIDs, lottery picks, points on Earth |
| **Words** | Diceware passphrases, invented words, brand names, real species, random knowledge |
| **Equations** | arithmetic, algebra, calculus, matrices — with clean answers and printable worksheets |
| **Patterns** | Perlin/Simplex/Worley noise, reaction–diffusion, cellular automata, mazes, WFC |
| **Images** | flow fields, superformula blobs, OKLCH palettes, identicons, public-domain art |
| **Audio** | noise colours, Euclidean rhythms, generative melodies, plucked strings, drones |

## Entropy sources

All free, all keyless, all credited — [probed live and documented](docs/03-entropy.md).

`OS CSPRNG` · `random.org` (atmospheric radio noise) · `ANU QRNG` (quantum vacuum) ·
`NIST Randomness Beacon` (signed, chained) · `drand` (League of Entropy) ·
`Bitcoin` (proof-of-work) · `USGS` (seismic) · `NOAA` (space weather) ·
`Open-Meteo` (atmosphere) · `ISS` (orbital position)

Sources are honestly classified. Cryptographic sources stand alone; an earthquake is
mixed with the OS CSPRNG and the receipt says so.

## Stack

Laravel 13 · PHP 8.5 · Blade + Alpine 3 + Tailwind 4 · Pest (unit tests only) ·
no database, no accounts, no API keys.

## Specification

Full spec in [`docs/`](docs/README.md) — architecture, every module's algorithms and
equations, entropy conditioning, API contract, brand direction, and build order.

## Status

📐 **Specification complete.** Implementation starts at
[Phase 0](docs/12-roadmap.md#phase-0--foundation-½-day).
