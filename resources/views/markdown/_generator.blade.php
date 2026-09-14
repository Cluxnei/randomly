@php
    /*
     * One generator, documented from its own declaration.
     *
     * Shared by `/g/{module}/{generator}.md` and by `/llms-full.txt`, which is why
     * the heading level is a variable: the same facts, at two depths, from one
     * template.
     */
    $renderers = [
        'text' => '`value` is the result itself — one value, or a list of them; `display` is the same thing rendered as a single human-readable string.',
        'chart' => '`value` is the sampled data the studio plots; `meta` carries the summary statistics measured from that very sample.',
        'math' => '`value.problems` is a list of problems with their answers; `meta.latex` carries the same in LaTeX.',
        'canvas' => '`value` is a **render spec** — algorithm, parameters, palette and a render key — not pixels. Ask for `format=png` to get the image.',
        'audio' => '`value` is a **score** — events, envelopes and synthesis parameters — not samples. Ask for `format=wav` to get the audio.',
        'gallery' => '`value` is a list of images with their per-image attribution.',
    ];

    // Three of the generator's own parameters at their own defaults: a line the
    // reader can paste, made of values that are actually accepted.
    $example = collect($entry['params'])
        ->take(3)
        ->mapWithKeys(fn (array $p) => [$p['name'] => is_bool($p['default']) ? ($p['default'] ? '1' : '0') : $p['default']])
        ->all();

    $query = $example === [] ? '' : '?'.http_build_query($example);
    $mediaFormat = $entry['renderer'] === 'canvas' ? 'png' : ($entry['renderer'] === 'audio' ? 'wav' : null);
@endphp
{{ $h }} `{{ $entry['key'] }}` — {{ $entry['name'] }}

{{ $entry['tagline'] }}

| | |
|---|---|
| **Key** | `{{ $entry['key'] }}` |
| **Module** | `{{ $entry['module'] }}` |
| **Version** | `{{ $entry['version'] }}` — a permalink carries this, and a replay against a different version returns `409` rather than something else |
| **Formats** | {{ implode(', ', array_map(fn ($f) => '`'.$f.'`', $entry['formats'])) }} |
| **Studio** | {{ $entry['url'] }} |
| **API** | `GET {{ $entry['api_url'] }}` |

{{ $renderers[$entry['renderer']] ?? '`value` carries the result.' }}
@if ($entry['sensitive'])

**Sensitive.** This generator produces secrets, so it is given no token and no shareable
permalink, and `/api/v1/replay` returns `404` for it. A secret with a replayable URL is
not a secret. Nothing is logged or stored either way — there is no database.
@endif
@if (! $entry['reproducible'])

**Not reproducible.** Built on live external material that moves, so the same seed
against tomorrow's data would pick something else. No permalink is issued, because a link
that quietly stops working is worse than no link.
@endif
@if (! $entry['uses_entropy'])

**Draws no entropy.** The output is a pure function of what you pass in, so no source is
consulted, nothing is spent waiting on a beacon, and the receipt says exactly that
instead of crediting a beacon that changed nothing.
@endif

@include('markdown._params', ['params' => $entry['params']])

```bash
curl -s '{{ $entry['api_url'] }}{{ $query }}' -H 'Accept: text/plain'
@if ($mediaFormat)
curl -o out.{{ $mediaFormat }} '{{ $entry['api_url'] }}{{ $query === '' ? '?' : $query.'&' }}format={{ $mediaFormat }}'
@endif
```
