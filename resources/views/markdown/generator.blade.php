@php
    $base = rtrim(url('/'), '/');
@endphp
# {{ $entry['name'] }} — Randomly

> {{ $entry['tagline'] }} One of {{ app(\App\Random\GeneratorRegistry::class)->all()->count() }} generators at [Randomly]({{ $base }}), a library of random generators seeded from verifiable real-world entropy. Free HTTP API, no key, no signup.

@include('markdown._generator', ['entry' => $entry, 'h' => '##'])

## The receipt

@if ($entry['uses_entropy'])
Every result carries one, naming the physical source of the randomness and linking to the
third party's own record of it:
@else
This generator draws no randomness, so its receipt says so rather than naming a source
that contributed nothing. Every generator that *does* draw randomness returns a receipt
shaped like this:
@endif

```json
"receipt": {
  "source": "seismic",
  "source_label": "USGS Seismic Feed",
  "class": "C",
  "caveat": "Carries only tens of bits of genuine surprise. Always mixed with the OS CSPRNG.",
  "narrative": "A magnitude 3.6 earthquake, 67 km N of Culebra, Puerto Rico, 39.6 km down — 52 minutes ago.",
  "proof_url": "https://earthquake.usgs.gov/earthquakes/eventpage/us7000th33",
  "mixed_with_csprng": true,
  "degraded": false
}
```

@if ($entry['uses_entropy'])
Pick the source with `?source=`, or leave it at the default `auto`:
@else
`?source=` is accepted and ignored here — there is nothing for a source to change. The
keys below apply to the other generators:
@endif

@foreach (collect($sources)->groupBy('class') as $class => $group)
- **Class {{ $class }}** ({{ $group->first()['class_label'] }}) — {{ $group->map(fn ($s) => '`'.$s['key'].'`')->implode(', ') }}@if ($group->first()['caveat']). {{ $group->first()['caveat'] }}@endif

@endforeach

## More

- [The full API contract]({{ $base }}/api.md)
- [Everything in one file]({{ $base }}/llms-full.txt)
- [The catalogue as JSON]({{ $base }}/api/v1/generators)
- [This generator in the browser]({{ $entry['url'] }})
