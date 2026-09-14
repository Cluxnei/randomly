---
name: randomly-api
description: Generate verifiable random data — numbers, passphrases, maths problems, procedural images, and audio — from the Randomly API, with provenance receipts naming the physical source of the entropy. Use when someone needs random values with an audit trail, reproducible procedural art or sound, sample/test data, worksheet problems, or is asking how to consume randomly's HTTP API.
---

# Randomly API

A free, keyless HTTP API returning random data seeded from real-world entropy — atmospheric
noise, quantum vacuum fluctuations, earthquakes, Bitcoin proof-of-work — with a receipt
naming the source and linking to third-party proof.

No signup, no key, no account. Rate limited per IP: 60 requests/minute, of which 20 may be
image or audio renders.

Base URL: `https://randomly.test/api/v1` (replace with the deployment you are calling).

## The one-liner

```bash
curl -s 'BASE/g/numbers.integers?count=6&min=1&max=60&unique=1' -H 'Accept: text/plain'
# 20, 22, 26, 35, 47, 51
```

## Discover what exists before calling it

```bash
curl -s 'BASE/generators'
```

Returns every generator with its declared parameters, so a client never has to guess:

```json
{"generators": [{
  "key": "numbers.integers",
  "name": "Integers",
  "module": "numbers",
  "renderer": "text",
  "formats": ["application/json", "text/plain"],
  "sensitive": false,
  "reproducible": true,
  "uses_entropy": true,
  "params": [
    {"name": "count", "type": "int", "default": 10, "min": 1, "max": 1000},
    {"name": "unique", "type": "bool", "default": false}
  ]
}]}
```

**Read `params` rather than guessing.** Out-of-range values are clamped, not rejected, so a
request always succeeds — but it may not do what you meant.

## Generating

```bash
# GET shorthand
curl -s 'BASE/g/{key}?param=value&source=drand'

# POST, for long parameter sets
curl -s -X POST 'BASE/generate' -H 'Content-Type: application/json' \
  -d '{"generator":"numbers.dice","params":{"notation":"4d6kh3","rolls":6},"source":"seismic"}'
```

Response:

```json
{
  "value": [20, 22, 26, 35, 47, 51],
  "display": "20, 22, 26, 35, 47, 51",
  "meta": {"entropy_out_bits": 34.2, "rejections": 2, "duration_us": 412},
  "receipt": {
    "source": "seismic",
    "class": "C",
    "caveat": "Carries only tens of bits of genuine surprise. Always mixed with the OS CSPRNG.",
    "narrative": "A magnitude 3.6 earthquake, 67 km N of Culebra, Puerto Rico — 52 minutes ago.",
    "proof_url": "https://earthquake.usgs.gov/earthquakes/eventpage/us7000th33",
    "mixed_with_csprng": true,
    "degraded": false
  },
  "seed": {"token": "Y9DSA6KVHC4YMVCE3A1SMFP9PR", "permalink": "…/r/Y9DSA6…"}
}
```

Use `value` for structured data and `display` for the human-readable form. `receipt` is the
point of the product — surface it if you are showing results to a person.

## Choosing a source

`?source=` takes any key from `BASE/sources`, or `auto` (the default, which picks a healthy
source at random).

| Class | Sources | Use for |
|---|---|---|
| **A** cryptographic | `csprng`, `anu-qrng`, `random-org` | anything where the randomness must be unpredictable |
| **B** public beacon | `nist-beacon`, `drand`, `bitcoin` | public, auditable, third-party-verifiable draws |
| **C** observational | `seismic`, `space-weather`, `atmosphere`, `iss` | narrative colour, never secrecy |

**Class B is published the moment it exists; Class C carries only tens of bits.** Both are
always mixed with the OS CSPRNG before use, and `receipt.mixed_with_csprng` says so. If you
need a secret, use Class A or do not use this API at all.

An unreachable source degrades to the CSPRNG rather than failing: check `receipt.degraded`.

## Images and audio

Canvas and audio generators return a *spec* as JSON by default. Ask for the rendered file:

```bash
curl -o noise.png 'BASE/g/patterns.perlin?variant=ridged&palette=viridis&format=png'
curl -o beat.wav  'BASE/g/audio.rhythm?format=wav'
```

Also honoured as `Accept: image/png` / `Accept: audio/wav`. Asking for a format a generator
cannot produce returns **406** naming the ones it can — `formats` in the catalogue tells you
in advance. Renders count against the smaller 20/minute allowance.

## Reproducing a result

The token **is** the seed, so a permalink recomputes rather than looks up:

```bash
curl -s 'BASE/replay/{token}?g=numbers.integers&v=1&p={base64url-params}'
```

Returns the identical `value` with `"replayed": true`. Two ways this deliberately fails:

- **409 `version_changed`** — the generator's algorithm changed, so the old output cannot be
  reproduced. It says so instead of quietly returning something different.
- **404** — the generator is `sensitive` (a password: a secret with a replayable URL is not a
  secret) or not `reproducible` (built on live external data that has since moved).

## What each module gives you

| Module | Use it for |
|---|---|
| `numbers` | integers, dice notation (`4d6kh3`), distributions, lottery draws, UUID/ULID, passwords, uniform points on Earth |
| `words` | Diceware passphrases with real entropy accounting, invented words, brand names, lorem, random real Wikipedia articles and species |
| `equations` | arithmetic, algebra, calculus, matrices — built backwards from the answer, so every problem is solvable and clean |
| `patterns` | Perlin/Simplex/Worley noise, reaction–diffusion, cellular automata, mazes, Wave Function Collapse |
| `images` | flow fields, superformula shapes, identicons, perceptual OKLCH palettes |
| `audio` | noise colours, Euclidean rhythms, generative melodies, plucked strings |

## Errors

| Code | Meaning |
|---|---|
| `400 invalid_params` | a parameter failed the generator's own schema; the response names the field |
| `404 unknown_generator` | not in the catalogue; the response lists what is |
| `406 unsupported_format` | that generator has no such representation; lists the ones it has |
| `409 version_changed` | replaying against a bumped generator |
| `429 rate_limited` | includes `retry_after_seconds` |

## Things worth knowing

- **Passwords and raw bytes are marked `sensitive`** and never get a shareable seed. Do not
  build a system that recovers a secret from a token; the API will not let you.
- **`uses_entropy: false`** means the generator is a pure function of its input (identicons).
  The receipt says so rather than crediting a source that changed nothing.
- **Parameters are clamped, never rejected**, so a slider drag cannot 422. Check the
  catalogue's `min`/`max` if exact values matter.
- For an LLM-readable copy of the docs: `BASE/../llms.txt` and `BASE/../api.md`.
