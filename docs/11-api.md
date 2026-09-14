# Public API

The site consumes exactly the same endpoints that third parties do. There is no private
API. This keeps one code path and makes the API a real product rather than an afterthought.

No authentication, no key, no signup — matching the philosophy of every source we
consume.

**Two rate-limit buckets, per IP.** 60 requests a minute overall, of which at most 20 may
be image or audio renders. The endpoints cost wildly different amounts: a JSON response is
microseconds of pure PHP, while a PNG or WAV starts a Node process and can take half a
second of CPU. One shared allowance would have to be set either low enough to protect the
box — needlessly throttling cheap calls — or high enough to let sixty renders a minute
saturate it. A media request is charged against *both* buckets.

**On "in memory":** PHP shares nothing between requests, so a genuinely in-process counter
would reset on every call and limit nothing at all. The limiter uses APCu when the
extension is present and the file cache otherwise — no service, no extension requirement,
and no database round trip just to decide whether to say no. Either way the count is
**per server**, which is the honest description: this is a showcase, not a distributed
rate-limit guarantee, and claiming otherwise would be the kind of overstatement the rest
of this project exists to avoid.

A `429` carries `retry_after_seconds` and both limits, because the caller is usually a
script or a language model rather than a person reading a page.

## `GET /api/v1/generators`

The whole catalogue, machine-readable. This is what makes "a library the user picks from"
literally true: the catalogue *is* data.

```json
{
  "generators": [
    {
      "key": "numbers.gaussian",
      "name": "Bell Curve",
      "tagline": "The normal distribution, drawn from real noise.",
      "module": "numbers",
      "version": 1,
      "renderer": "chart",
      "formats": ["application/json", "text/plain"],
      "sensitive": false,
      "reproducible": true,
      "uses_entropy": true,
      "params": [
        {"name":"count","type":"int","label":"How many","default":100,"min":1,"max":10000},
        {"name":"mu","type":"float","label":"Mean (μ)","default":0,"min":-1000,"max":1000},
        {"name":"sigma","type":"float","label":"Spread (σ)","default":1,"min":0.01,"max":100}
      ]
    }
  ]
}
```

## `GET /api/v1/sources`

```json
{
  "sources": [
    {"key":"nist-beacon","label":"NIST Randomness Beacon","class":"A",
     "status":"up","latency_ms":515,"refresh_seconds":60,
     "current":"pulse #1937618","proof_url":"https://beacon.nist.gov/beacon/2.0/chain/2/pulse/1937618"},
    {"key":"seismic","label":"USGS Seismic Feed","class":"C",
     "status":"up","latency_ms":416,"refresh_seconds":60,
     "current":"M4.2 · 31km off Honshu"}
  ]
}
```

## `POST /api/v1/generate`

```json
{
  "generator": "numbers.integers",
  "params": {"count": 6, "min": 1, "max": 60, "unique": true},
  "source": "seismic"
}
```

`source` is optional; omit it for `auto` (picks a healthy Class A source, round-robin).

**200 response:**

```json
{
  "value": [7, 13, 24, 31, 45, 58],
  "display": "7, 13, 24, 31, 45, 58",
  "meta": {
    "entropy_in_bytes": 32,
    "entropy_out_bits": 25.6,
    "rejections": 0,
    "duration_us": 412
  },
  "receipt": {
    "source": "seismic",
    "source_label": "USGS Seismic Feed",
    "narrative": "A magnitude 4.2 earthquake, 31 km off the coast of Honshu, 6 minutes ago.",
    "proof_url": "https://earthquake.usgs.gov/earthquakes/eventpage/us7000abcd",
    "observed_at": "2026-09-12T02:14:08Z",
    "degraded": false,
    "mixed_with": "csprng",
    "class": "C"
  },
  "seed": {
    "token": "K7M2P9QX4T",
    "permalink": "https://randomly.test/r/K7M2P9QX4T?g=numbers.integers&v=1"
  }
}
```

`mixed_with` is always present and always honest — it states that a Class B or C source
was combined with the CSPRNG, exactly as `03-entropy.md` §5 describes.

## `GET /api/v1/replay/{token}`

Recomputes a past result. The token is the seed (26 Crockford base32 characters), so
this needs no storage. Requires `g` (generator key) and `v` (version); `p`
(base64url-encoded params) defaults to the generator's defaults, and `s`/`r` optionally
carry the receipt's provenance (see below).

Sensitive generators have no permalink at all and return `404` here — see
`03-entropy.md` §4.

Returns the identical `value`, with `"replayed": true`.

**The receipt on a replay.** With no database there is nothing to look the original
receipt up in, so the permalink carries the two fields needed to rebuild it: `s` (the
source key) and `r` (that source's own reference — a NIST pulse index, a drand round, a
block hash, a USGS event id). From those we reconstruct the narrative and the proof link,
and mark the receipt `"reconstructed": true`. What we will not do is invent a fresh
receipt for an old result and present it as the original.

If the generator's current version ≠ `v`, responds `409` with:

```json
{
  "error": "version_changed",
  "message": "numbers.integers is now v2. This link was made with v1 and can no longer be reproduced exactly.",
  "generated_with": 1,
  "current": 2
}
```

Honest failure beats a silently different answer — reproducibility that quietly stops
being reproducible is worse than none.

## Errors

| Code | Meaning |
|---|---|
| `400 invalid_params` | Failed the generator's own schema validation; the response lists offending fields |
| `404 unknown_generator` | Not in the registry |
| `409 version_changed` | Replay against a bumped generator |
| `429 rate_limited` | Includes `Retry-After` |
| `503 all_sources_down` | Never returned — CSPRNG always answers. Documented as unreachable on purpose |

## Output formats

Every generator is fully usable over HTTP. A canvas or audio generator returns a spec
by design — that is what keeps the studio's responses small and its sliders instant —
but an API caller handed a *description* of an image has not been given an image.

| Accept / `?format=` | Returns | Applies to |
|---|---|---|
| `application/json` (default) | value, display, meta, receipt, seed | everything |
| `text/plain` | just `display` | everything |
| `image/png` | the rendered image | the 8 canvas generators |
| `audio/wav` | 16-bit PCM, 44.1 kHz stereo | the 5 audio generators |

`?format=png` is honoured alongside the `Accept` header, because
`curl -o out.png '...&format=png'` is far easier to type and remember.

**Rendered by the browser's own code.** PNG and WAV are produced by running the same
JavaScript modules the browser runs, under Node. A PHP reimplementation of the
renderers was the obvious alternative and the wrong one: two implementations of a
generative algorithm drift, silently, somewhere nobody looks. The scripts doing this
were written to *verify* the renderers (`scripts/render-preview.mjs`,
`scripts/render-wav.mjs`); having them serve the renderers too costs nothing and
guarantees an API response matches what the studio draws.

Cold render is 50–500 ms, so everything is cached on a hash of exactly what gets
drawn — not of the request, so query-string order does not fragment the cache. Audio
is capped at 60 seconds; a longer score is truncated and says so rather than letting
a request buy ten minutes of synthesis.

**Asking for a format a generator cannot produce returns `406`, not a silent JSON
fallback**, and names the ones it can:

```json
{
  "error": "unsupported_format",
  "message": "[words.passphrase] is a text generator and has no PNG representation.",
  "available": ["application/json", "text/plain"]
}
```

`/api/v1/generators` advertises the formats for each entry, so a client never has to
discover this by failing.

```bash
curl -s 'https://randomly.test/api/v1/g/numbers.integers?count=6&min=1&max=60&unique=1' \
  -H 'Accept: text/plain'
# 7, 13, 24, 31, 45, 58
```

A `GET` shorthand (`/api/v1/g/{key}?...`) exists alongside the `POST` form purely so
that one-liner is possible. Being `curl`-able in a single line without a key is itself
a marketing feature — it's what gets posted.

## Attribution we owe

Rendered in the site footer and returned in `/api/v1/sources`:

- EFF wordlists — CC BY 3.0 US
- Wikipedia summaries — CC BY-SA 4.0
- Lorem Picsum / Unsplash — photographer credit per image
- The Met (CC0), Art Institute of Chicago (public domain)
- random.org, ANU QRNG, NIST, drand, USGS, NOAA, Open-Meteo, mempool.space,
  Datamuse, GBIF, Open Library

Free does not mean uncredited. The credits page doubles as a "look at everything this
thing touches" flex.
