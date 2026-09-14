@php
    /*
     * One generator's declared parameters, as a markdown table.
     *
     * Rendered from ParamSchema::toArray() — the same declaration that builds the
     * studio's control panel and the API's validation rules — so the bounds
     * printed here are the bounds actually enforced.
     */
    $pipe = fn (?string $s): string => str_replace('|', '\|', (string) $s);

    $literal = function (mixed $v): string {
        return match (true) {
            is_bool($v) => $v ? '`true`' : '`false`',
            $v === null => '`null`',
            $v === '' => '`""`',
            is_float($v) => '`'.rtrim(rtrim(sprintf('%.4f', $v), '0'), '.').'`',
            is_array($v) => '`'.json_encode($v, JSON_UNESCAPED_SLASHES).'`',
            default => '`'.$v.'`',
        };
    };

    $bounds = function (array $p) use ($literal, $pipe): string {
        if ($p['type'] === 'enum') {
            return implode(', ', array_map(fn ($k) => '`'.$k.'`', array_keys($p['options'] ?? [])));
        }

        if ($p['type'] === 'bool') {
            return '`true`, `false`';
        }

        if ($p['type'] === 'string') {
            return isset($p['max']) ? 'up to '.(int) $p['max'].' characters' : 'free text';
        }

        $min = $p['min'] ?? null;
        $max = $p['max'] ?? null;

        return match (true) {
            $min !== null && $max !== null => $literal($p['type'] === 'int' ? (int) $min : $min).' – '.$literal($p['type'] === 'int' ? (int) $max : $max),
            $min !== null => '≥ '.$literal($p['type'] === 'int' ? (int) $min : $min),
            $max !== null => '≤ '.$literal($p['type'] === 'int' ? (int) $max : $max),
            default => '—',
        };
    };
@endphp
@if ($params === [])
This generator takes no parameters.
@else
| Parameter | Type | Default | Accepts | What it does |
|---|---|---|---|---|
@foreach ($params as $p)
| `{{ $p['name'] }}` | {{ $p['type'] }} | {{ $literal($p['default']) }} | {{ $bounds($p) }} | {{ $pipe($p['label']) }}@isset($p['help']) — {{ $pipe($p['help']) }}@endisset |
@endforeach

Out-of-range numbers are **clamped, not rejected**, so a request never fails for being
ambitious — but it may not do what you meant. Unknown keys are dropped and missing ones
fall back to the defaults above.
@endif
