@php
    /*
     * The API contract, written once.
     *
     * `/api.md` renders this under an H1 and `/llms-full.txt` renders it a level
     * deeper, which is the whole reason $h exists — two copies of a contract is
     * how a contract starts contradicting itself.
     */
    $base = rtrim(url('/'), '/');
    $api = $base.'/api/v1';
    $perMinute = (int) config('randomly.limits.per_minute', 60);
    $mediaPerMinute = (int) config('randomly.limits.media_per_minute', 20);
    $canvas = collect($byModule)->pluck('generators')->flatten(1)->where('renderer', 'canvas');
    $audio = collect($byModule)->pluck('generators')->flatten(1)->where('renderer', 'audio');
@endphp
{{ $h }} Base URL

```
{{ $api }}
```

No authentication, no key, no signup, no account — matching every source this project
draws from. The site's own pages call these same endpoints; there is no private path
with different behaviour.

**Rate limits, per IP.** {{ $perMinute }} requests a minute overall, of which at most
{{ $mediaPerMinute }} may be PNG or WAV renders. A render starts a Node process and costs
a hundred times what a JSON response costs, so it is charged against *both* buckets. A
`429` carries `retry_after_seconds` and both limits, because the caller is usually a
script or a model rather than a person reading a page. The count is per server, not
distributed — this is a showcase, and claiming otherwise would be the kind of
overstatement the rest of the project exists to avoid.

{{ $h }} The one-liner

```bash
curl -s '{{ $api }}/g/numbers.integers?count=6&min=1&max=60&unique=1' -H 'Accept: text/plain'
# 20, 22, 26, 35, 47, 51
```

{{ $h }} Endpoints

| Method | Path | Returns |
|---|---|---|
| `GET` | `/api/v1/generators` | the whole catalogue with every generator's declared parameters |
| `GET` | `/api/v1/sources` | the {{ count($sources) }} entropy sources, their class and live status |
| `GET` | `/api/v1/g/{key}?…` | generate — the shorthand, for one-liners |
| `POST` | `/api/v1/generate` | generate — `{"generator", "params", "source"}` as JSON |
| `GET` | `/api/v1/replay/{token}?g=&v=` | recompute a past result exactly |

{{ $h }} Discover before you call

```bash
curl -s '{{ $api }}/generators'
```

Every entry carries `key`, `name`, `tagline`, `module`, `renderer`, `version`, the
`formats` it can be served as, the three behaviour flags below, and `params` — each with
its `type`, `default`, and `min`/`max` or `options`. **Read `params` rather than
guessing.** Numbers outside their bounds are clamped rather than rejected, so a request
always succeeds, but it may not do what you meant.

Three flags decide what a client may do with a generator:

| Flag | What it tells a caller |
|---|---|
| `sensitive` | `true` for a generator that produces secrets — a password, a passphrase, an identifier. It is given **no token and no permalink**, and `/api/v1/replay` returns `404`. A secret with a replayable URL is not a secret. |
| `reproducible` | `true` means a permalink recomputes the result exactly. `false` for a generator built on live external material that moves, so no permalink is issued — a link that quietly stops working is worse than no link. |
| `uses_entropy` | `true` for almost everything. `false` for a pure function of its input (an identicon): no source is consulted, nothing is spent waiting on a beacon, and the receipt says so rather than crediting a source that changed nothing. |

{{ $h }} Generating

```bash
# GET shorthand
curl -s '{{ $api }}/g/numbers.dice?notation=4d6kh3&rolls=6'

# POST, for long parameter sets
curl -s -X POST '{{ $api }}/generate' \
     -H 'Content-Type: application/json' \
     -d '{"generator":"numbers.integers","params":{"count":6,"min":1,"max":60,"unique":true},"source":"drand"}'
```

The JSON envelope is the same for every generator:

| Field | Type | What it is |
|---|---|---|
| `value` | varies | the structured result — a list of numbers, a string, a render spec |
| `display` | string | the human-readable form, and the entire body of a `text/plain` response |
| `meta` | object | instrumentation: `entropy_out_bits`, `rejections`, `duration_us`, and whatever else the generator measured |
| `receipt` | object | where the randomness came from — see below |
| `seed.token` | string\|null | 26 Crockford base32 characters. This **is** the seed. `null` for a sensitive generator. |
| `seed.permalink` | string\|null | a URL that recomputes this exact result |

{{ $h }} The receipt

The receipt is the product. Every generated result carries one:

| Field | What it is |
|---|---|
| `source` / `source_label` | which of the {{ count($sources) }} sources answered |
| `class` | `A` cryptographic, `B` public beacon, `C` observational |
| `caveat` | the honest limitation of that class, in words, or `null` for Class A |
| `narrative` | one sentence a human can read: the earthquake, the pulse index, the block |
| `proof_url` | a link to the third party's own record of it, so the claim is checkable |
| `reference` | that source's own identifier — a pulse index, a drand round, a block hash |
| `observed_at` | when the material was observed, ISO 8601 |
| `mixed_with_csprng` | `true` whenever a Class B or C source was folded in with fresh local entropy |
| `degraded` | `true` if the requested source was unreachable and the kernel answered instead |

{{ $h }} Choosing an entropy source

`?source=` takes any key below, or `auto` (the default).

| Key | Source | Class | What it physically is |
|---|---|---|---|
@foreach ($sources as $source)
| `{{ $source['key'] }}` | {{ $source['label'] }} | {{ $source['class'] }} | {{ str_replace('|', '\|', $source['origin']) }} |
@endforeach

**Class A** stands alone. **Class B** is unpredictable until published and public forever
after. **Class C** is flavour — an earthquake carries tens of bits of genuine surprise,
not the kilobytes its JSON weighs. Class B and C are therefore *always* mixed with fresh
CSPRNG bytes before anything is generated, and the receipt says so. **If you need a
secret, ask for Class A or do not use this API at all.**

An unreachable source degrades to the kernel rather than failing the request; check
`receipt.degraded`.

{{ $h }} Output formats

| `Accept:` / `?format=` | Returns | Available on |
|---|---|---|
| `application/json` (default) | the envelope above | every generator |
| `text/plain` | just `display` | every generator |
| `image/png` | the rendered image | the {{ $canvas->count() }} canvas generators |
| `audio/wav` | 16-bit PCM, 44.1 kHz | the {{ $audio->count() }} audio generators |

```bash
curl -o noise.png '{{ $api }}/g/patterns.perlin?variant=ridged&palette=viridis&format=png'
curl -o beat.wav  '{{ $api }}/g/audio.rhythm?format=wav'
```

Canvas and audio generators return a **render spec** as JSON by default — the algorithm,
its parameters, the palette and a render key — rather than pixels or samples, because the
browser re-derives them from the same byte stream. That keeps a four-minute generative
piece a 2 KB response. An API caller who wants the artefact asks for `format=png` or
`format=wav`; those are rendered by running the same JavaScript the browser runs, under
Node, so the file matches what the studio draws.

Asking for a format a generator cannot produce returns **`406`** naming the ones it can,
never a silent JSON fallback. `formats` in the catalogue tells you in advance.

{{ $h }} Reproducing a result

There is no database. The token **is** the seed, so a permalink recomputes rather than
looks up:

```bash
curl -s '{{ $api }}/replay/{token}?g=numbers.integers&v=1&p={base64url-params}'
```

`g` (generator key) and `v` (version) are required; `p` defaults to the generator's
defaults. `s` and `r` optionally carry the original source key and its reference, from
which the receipt is rebuilt and marked `reconstructed` — because inventing a fresh
receipt for an old result and presenting it as the original is exactly the lie this
project exists not to tell.

The response is identical to a generation, plus `"replayed": true`.

{{ $h }} Errors

| Status | `error` | Meaning |
|---|---|---|
| `400` | `invalid_params` | a parameter failed the generator's own schema; `fields` names it |
| `400` | `invalid_token` | the token is not 26 valid Crockford base32 characters |
| `404` | `unknown_generator` | not in the catalogue; `available` lists every key that is |
| `404` | `not_replayable` | that generator is `sensitive`, so it never had a replayable link |
| `406` | `unsupported_format` | no such representation; `available` lists the ones there are |
| `409` | `version_changed` | the generator's algorithm changed, so the old output cannot be reproduced. It says so rather than quietly returning something different. |
| `429` | `rate_limited` | includes `retry_after_seconds` and both limits |

`503` is documented as unreachable on purpose: the kernel CSPRNG always answers, so
"all sources down" cannot happen.
