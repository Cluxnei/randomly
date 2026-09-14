@php
    $base = rtrim(url('/'), '/');
    $byClass = collect($sources)->groupBy('class');
@endphp
# Randomly — complete documentation

> A library of {{ $count }} random generators — numbers, words, equations, patterns, images and sound — seeded from {{ count($sources) }} real-world entropy sources, with a free, keyless HTTP API. Every result carries a receipt naming the physical source of its randomness and linking to third-party proof, and every result replays bit-for-bit from its own URL.

This file is generated from the live registry: every generator, parameter, bound and
default below is read from the same declaration the running site and the API use, so it
cannot drift from the code. Shorter versions: [`/llms.txt`]({{ $base }}/llms.txt) (index)
and [`/api.md`]({{ $base }}/api.md) (the contract alone).

## What this is

Most random tools call `rand()` and move on. Randomly makes the *source* of the
randomness the product. A draw might come from vacuum field fluctuations measured by
homodyne detection in Canberra, from atmospheric radio noise sampled in Dublin, from the
NIST beacon's minute pulse, from the block hash the Bitcoin network spent ten minutes
finding, or from an earthquake the USGS recorded forty minutes ago — and the receipt says
which, in a sentence a person can read, with a link to the third party's own record of it.

Four structural facts a client should know before calling anything:

1. **No database, no accounts.** A result's token is 16 bytes in 26 Crockford base32
   characters, and that token *is* the seed. A permalink recomputes the result rather
   than looking it up. Nothing is stored, so nothing can be retrieved.
2. **One contract.** A generator declares a parameter schema, and the studio's controls,
   the API's validation, the catalogue entry and the permalink format all render from
   that one declaration. `/api/v1/generators` is that declaration, machine-readable.
3. **Specs, not pixels.** Canvas and audio generators return a *description* — algorithm,
   parameters, palette, render key — which the browser turns into pixels or samples from
   the same byte stream. An API caller who wants the artefact asks for `format=png` or
   `format=wav` and gets it, rendered by running the browser's own JavaScript under Node.
4. **Honest failure.** A replay against a changed generator returns `409` rather than
   quietly returning something different. A format a generator cannot produce returns
   `406` naming the ones it can, rather than a silent JSON fallback.

## Where the randomness comes from

All {{ count($sources) }} of them are free, keyless and credited — and classified honestly,
because an earthquake is a wonderful story and a poor cipher.

| Key | Source | Class | Refresh | What it physically is |
|---|---|---|---|---|
@foreach ($sources as $source)
| `{{ $source['key'] }}` | {{ $source['label'] }} | {{ $source['class'] }} | {{ $source['refresh_seconds'] === 0 ? 'on demand' : $source['refresh_seconds'].'s' }} | {{ str_replace('|', '\|', $source['origin']) }} |
@endforeach

@foreach ($byClass as $class => $group)
**Class {{ $class }} — {{ $group->first()['class_label'] }}.** {{ $group->map(fn ($s) => '`'.$s['key'].'`')->implode(', ') }}. {{ $group->first()['caveat'] ?? 'Full-entropy, unpredictable bytes; safe to stand alone.' }}

@endforeach
**The mixing rule.** Class B and Class C material is *always* combined with fresh OS
CSPRNG bytes, a nanosecond clock reading and a monotonic counter before HKDF-SHA256
conditions it into a 16-byte seed. So the exotic source genuinely contributes and can be
pointed at, and the result is unpredictable regardless. `receipt.mixed_with_csprng` states
which happened. **If you need a secret, ask for Class A** — or do not use a public HTTP
API for it at all.

**Degradation.** Every external source has a short timeout and a circuit breaker. When
one is unreachable the kernel CSPRNG answers instead and the receipt is marked
`"degraded": true` with the substitution spelled out in the narrative. The request does
not fail; the receipt does not lie about what happened.

**No entropy at all, sometimes.** A generator that is a pure function of its input — an
identicon is the same avatar for the same string, forever, which is the entire point of an
identicon — skips the entropy draw rather than crediting a beacon that changed nothing
about the output. `uses_entropy` in the catalogue says which those are.

## The API

@include('markdown._api', ['h' => '###', 'byModule' => $byModule, 'sources' => $sources, 'count' => $count])

## The generators

{{ $count }} of them, across {{ count($byModule) }} modules. Every name, bound, default and
option below is read from that generator's own `schema()` — the same declaration the
studio's controls and the API's validation are built from.
@foreach ($byModule as $group)

### {{ $group['module']->label() }}

{{ $group['module']->tagline() }}
@foreach ($group['generators'] as $entry)

@include('markdown._generator', ['entry' => $entry, 'h' => '####'])
@endforeach
@endforeach

## Attribution

This project stands on work it did not pay for and could not have afforded: RANDOM.ORG,
the Australian National University, NIST, the League of Entropy, mempool.space, the USGS,
NOAA, Open-Meteo, Open Notify, Wikipedia, GBIF, and the EFF's Diceware wordlists
(CC BY 3.0 US). Not one of them charges. Not one asked for a key. Every one of them could
have. The full list, with licences, is at [{{ $base }}/credits]({{ $base }}/credits).

If you build on this API, crediting the entropy source named in the receipt is the thing
worth doing — it is the source, not this site, that makes the claim checkable.

- [Source code](https://github.com/Cluxnei/randomly)
- [Index]({{ $base }}/llms.txt)
- [API contract alone]({{ $base }}/api.md)
