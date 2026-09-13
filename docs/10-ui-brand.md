# UI, Brand & Copy

This is a marketing showcase. The interface is the product demo, so the presentation
layer gets first-class spec treatment.

## 1. Positioning in one screen

The landing page must answer three questions above the fold:
**What is it?** (a library of random generators) · **Why is it different?** (the
randomness comes from the physical world, with receipts) · **Can I try it right now?**
(yes — the hero *is* a live generator).

## 2. Visual direction

**Dark-first, instrument-like.** The reference points are an oscilloscope, a seismograph
and a Swiss print grid — not a SaaS dashboard.

```
Ground      oklch(0.15 0.01 260)   near-black, faintly blue
Surface     oklch(0.20 0.015 260)  raised cards
Line        oklch(0.30 0.02 260)   hairlines, 1px, everywhere
Text        oklch(0.96 0.005 260)
Muted       oklch(0.65 0.015 260)
Signal      oklch(0.78 0.19 145)   the accent — phosphor green
Warn        oklch(0.75 0.17 65)    degraded entropy
```

One accent colour only. Generated output supplies all the other colour on screen —
which means the palette engine's output is literally the site's decoration, and the site
looks different on every visit. That is the brand.

**Type:** a grotesque for UI (Inter Variable), a mono for anything numeric, seeds,
hashes, or code (JetBrains Mono). Numbers are *always* mono and tabular — it makes the
output look measured rather than decorative. Headlines are large and tight
(`text-6xl tracking-tighter`).

**Motion:** restrained and physical. Results arrive with a 120ms fade + 4px rise, never
a bounce. The only continuously animated things are the hero canvas and the entropy
ticker — because those represent something genuinely live.

**Grain:** a fixed 3% noise overlay on the page background (a tiled base64 PNG, ~2 KB).
It ties the flat dark ground to the generative output.

## 3. Page by page

### `/` — Landing

1. **Hero.** Full-bleed flow-field canvas, drawn from a seed fetched from a real source
   *on page load*. Headline over it: `Randomness, sourced from reality.` Sub: one line
   naming that visit's actual source — "This page was drawn from a magnitude 4.2
   earthquake off Honshu, 6 minutes ago." The page is its own proof.
2. **Live entropy ticker.** A horizontal strip: NIST pulse number, drand round, Bitcoin
   block height, last earthquake, Kp index — each ticking in real time, monospace, with
   a green pulse dot. Costs one 5s poll of a cached endpoint. It is the single most
   convincing element on the site.
3. **The catalogue teaser.** Six generator cards with live, animated previews.
4. **How it works.** Three steps: collect → condition → generate, with the HKDF diagram
   drawn as an inline SVG.
5. **Receipts.** A real receipt card, with a working link out to `beacon.nist.gov`.
   Clicking through to a third-party site that confirms our claim is the trust moment.

### `/library` — Catalogue

Dense grid, filterable by module and by entropy source. Every card previews live: number
generators tick digits, pattern generators animate their canvas at low frame rate,
audio generators show a waveform that plays on hover. Card = `name`, `tagline`, module
chip, preview. Search filters instantly (Alpine, client-side — the whole registry is
under 50 entries).

### `/g/{module}/{generator}` — Studio

```
┌──────────────────────────────┬────────────────────┐
│                              │  CONTROLS          │
│      R E S U L T             │  (from schema)     │
│      (dominant, centred)     │                    │
│                              │  Entropy source ▾  │
│                              │  ┌──────────────┐  │
│                              │  │  GENERATE    │  │
├──────────────────────────────┤  └──────────────┘  │
│  RECEIPT · source · proof ↗  │  copy · png · wav  │
│  meta: 32 bytes in · 6.6 bits out · 0.4ms        │
└──────────────────────────────┴────────────────────┘
```

Space bar regenerates. The parameter panel is generated wholesale from `schema()`.
Changing a parameter re-renders client-side from the same seed (instant); pressing
Generate fetches a new seed. That distinction — *same randomness, different parameters*
vs *new randomness* — is made visible with two different buttons, and it teaches the
seed concept without a word of explanation.

### `/entropy` — The dashboard

A card per source: status dot, class (A/B/C), current value, latency sparkline, refresh
period, proof link, and a live von Neumann debiasing animation for the Class C ones.
This page exists to be screenshotted and posted.

### `/r/{token}` — Permalink

Renders the exact result plus a "regenerate with new entropy" button and the original
receipt, dated. Open Graph image is baked server-side with GD so shared links preview
the actual artwork.

## 4. Copy rules

- **Concrete over abstract.** Not "high-quality randomness" — "12.9 bits per word,
  from a list of 7,776."
- **Name the source, always.** Every number on screen should be traceable to a sentence
  a human can read.
- **Never oversell.** Class C sources are labelled as flavour, not as cryptography.
  The honesty is the differentiator; a marketing site that admits a limitation is the
  one people trust. `/entropy` states plainly: *"An earthquake contributes maybe 30 bits
  of genuine surprise. We mix it with 256 bits from your OS. Both facts are on the receipt."*
- **Verbs on buttons.** `Generate`, `Reroll`, `Copy`, `Download WAV` — never `Submit`.
- **Numbers are the decoration.** Bits, milliseconds, block heights, pulse indices.
  Show the instrumentation.

## 5. Shareables (the growth loop)

Every result produces, with no extra work: a permalink, an OG card with the artwork and
the receipt line, a PNG (patterns/images), a WAV (audio), a printable worksheet
(equations), and a copyable text block (numbers/words). The receipt line is embedded in
each export — an exported image carries the sentence *"Generated from NIST Beacon pulse
#1937618"* in its corner. Every share is an advertisement that explains the product.

## 6. Accessibility, briefly

Contrast pairs validated by the same OKLCH engine that generates palettes (so the tool
enforces its own rules). Keyboard: `Space` regenerate, `C` copy, `S` save, `/` search.
`prefers-reduced-motion` freezes the hero canvas to a single static frame and stops the
ticker animation. Every canvas has a text alternative describing the algorithm and seed.
Audio never autoplays.
