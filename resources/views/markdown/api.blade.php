@php
    $base = rtrim(url('/'), '/');
@endphp
# Randomly API

> A free, keyless HTTP API returning random data — numbers, passphrases, maths problems, procedural images and audio — seeded from {{ count($sources) }} real-world entropy sources, with a receipt naming the physical source and linking to third-party proof. No signup, no key, no account. {{ $count }} generators.

@include('markdown._api', ['h' => '##', 'byModule' => $byModule, 'sources' => $sources, 'count' => $count])

## Every generator key

Each links to its own documentation, generated from its declared schema.

@foreach ($byModule as $group)
**{{ $group['module']->label() }}** — {{ $group['module']->tagline() }}

@foreach ($group['generators'] as $g)
- [`{{ $g['key'] }}`]({{ $g['markdown_url'] }}): {{ $g['tagline'] }}
@endforeach

@endforeach
Or fetch the whole catalogue with its parameters at once:

```bash
curl -s '{{ $base }}/api/v1/generators'
```

## More

- [Everything in one file]({{ $base }}/llms-full.txt) — this contract plus all {{ $count }} generators and all {{ count($sources) }} entropy sources.
- [Index]({{ $base }}/llms.txt) — the short version, per the llmstxt.org convention.
- [Source code](https://github.com/Cluxnei/randomly)
