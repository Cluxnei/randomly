@php
    $base = rtrim(url('/'), '/');
    $classA = collect($sources)->where('class', 'A')->count();
@endphp
# Randomly

> A library of {{ $count }} random generators — numbers, words, equations, patterns, images and sound — seeded from {{ count($sources) }} real-world entropy sources, with a free, keyless HTTP API. Every result carries a receipt naming the physical source of its randomness and linking to third-party proof, and every result replays bit-for-bit from its own URL.

Most random tools call `rand()` and move on. Here the *source* is the product: a draw
might come from vacuum fluctuations measured in Canberra, from atmospheric noise sampled
in Dublin, from the NIST beacon, from a Bitcoin block hash, or from an earthquake the
USGS recorded forty minutes ago — and the receipt says which, with a link you can check.

Sources are classified honestly: **A** cryptographic, **B** public beacon (unpredictable
until published, public forever after), **C** observational (tens of bits of genuine
surprise, flavour rather than cryptography). B and C are *always* mixed with fresh OS
CSPRNG bytes before anything is generated, and the receipt states that too. If you need a
secret, ask for Class A.

There is no database and there are no accounts. A result's 26-character token *is* its
seed, so a permalink recomputes the result instead of looking it up.

## Start here

- [API contract]({{ $base }}/api.md): every endpoint, parameter, format and error code. Free, keyless, {{ (int) config('randomly.limits.per_minute', 60) }} requests a minute per IP.
- [Everything in one file]({{ $base }}/llms-full.txt): the contract plus all {{ $count }} generators with their parameters and all {{ count($sources) }} entropy sources.
- [Catalogue as JSON]({{ $base }}/api/v1/generators): the machine-readable registry — every generator's declared parameters, defaults and bounds.
- [Entropy sources as JSON]({{ $base }}/api/v1/sources): live status, class and current value of each source.

## Try it without reading anything

- [`curl -s '{{ $base }}/api/v1/g/numbers.integers?count=6&min=1&max=60&unique=1' -H 'Accept: text/plain'`]({{ $base }}/api/v1/g/numbers.integers?count=6&min=1&max=60&unique=1): six numbers and nothing else. Drop the header for the full JSON envelope with the receipt.
@foreach ($byModule as $group)

## {{ $group['module']->label() }}

{{ $group['module']->tagline() }}

@foreach ($group['generators'] as $g)
- [`{{ $g['key'] }}`]({{ $g['markdown_url'] }}): {{ $g['tagline'] }}
@endforeach
@endforeach

## Pages

- [Library]({{ $base }}/library): all {{ $count }} generators as a filterable catalogue.
- [Entropy]({{ $base }}/entropy): the {{ count($sources) }} sources, live, with what each is honestly worth.
- [Credits]({{ $base }}/credits): every source, corpus and licence this stands on. Free does not mean uncredited.

## Optional

- [Source code](https://github.com/Cluxnei/randomly): Laravel, PHP, Blade, Alpine and Tailwind. The specification the project was built from lives in `docs/`.
