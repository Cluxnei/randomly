<?php

declare(strict_types=1);

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Studio\Generation;

it('fills in every declared parameter from defaults', function (): void {
    $schema = ParamSchema::make()
        ->int('count', 'How many', default: 10, min: 1, max: 100)
        ->bool('unique', 'No repeats', default: true);

    expect($schema->coerce([])->all())->toBe(['count' => 10, 'unique' => true]);
});

it('clamps rather than rejects, because a dragged slider should saturate', function (): void {
    $schema = ParamSchema::make()->int('count', 'How many', default: 10, min: 1, max: 100);

    expect($schema->coerce(['count' => 5000])->int('count'))->toBe(100)
        ->and($schema->coerce(['count' => -99])->int('count'))->toBe(1);
});

it('ignores keys it never declared', function (): void {
    $schema = ParamSchema::make()->int('count', 'How many', default: 3);

    // Anything can arrive from a query string; generate() should never have to
    // defend itself against the request.
    expect($schema->coerce(['count' => 5, 'drop_tables' => 'yes', 'nested' => ['a']])->all())
        ->toBe(['count' => 5]);
});

it('casts whatever the query string gave it to the declared type', function (): void {
    $schema = ParamSchema::make()
        ->int('count', 'How many', default: 1)
        ->float('sigma', 'Spread', default: 1.0)
        ->bool('unique', 'No repeats');

    $params = $schema->coerce(['count' => '42', 'sigma' => '2.5', 'unique' => 'true']);

    expect($params->int('count'))->toBe(42)
        ->and($params->float('sigma'))->toBe(2.5)
        ->and($params->bool('unique'))->toBeTrue();
});

it('reads "0" and "false" from a checkbox as false', function (string $falsey): void {
    $schema = ParamSchema::make()->bool('unique', 'No repeats', default: true);

    expect($schema->coerce(['unique' => $falsey])->bool('unique'))->toBeFalse();
})->with([['0'], ['false'], ['']]);

it('builds validation rules from the same declaration that renders the controls', function (): void {
    $rules = ParamSchema::make()
        ->int('count', 'How many', default: 10, min: 1, max: 100)
        ->enum('sort', 'Order', ['none' => 'As drawn', 'asc' => 'Ascending'], default: 'none')
        ->rules();

    expect($rules['count'])->toContain('integer', 'min:1', 'max:100')
        ->and($rules['sort'])->toContain('in:none,asc');
});

it('orders keys canonically so a decoded permalink derives the same stream', function (): void {
    $a = new Params(['min' => 1, 'max' => 100, 'count' => 5]);
    $b = new Params(['count' => 5, 'max' => 100, 'min' => 1]);

    expect($a->fingerprint())->toBe($b->fingerprint());
});

it('round-trips parameters through a url-safe encoding', function (): void {
    $params = ['notation' => '4d6kh3+2', 'rolls' => 6, 'unique' => true];

    $encoded = rtrim(strtr(base64_encode(json_encode($params)), '+/', '-_'), '=');

    expect(Generation::decodeParams($encoded))->toBe($params)
        ->and($encoded)->not->toContain('+', '/', '=');
});

it('treats a corrupted permalink as no parameters rather than an exception', function (string $garbage): void {
    // A truncated or hand-edited link should fall back to the generator's defaults,
    // not 500. Somebody will always trim a character off the end of a URL.
    expect(Generation::decodeParams($garbage))->toBe([]);
})->with([[''], ['!!!!'], ['bm90LWpzb24'], ['eyJhIjo'], ['WyJhbiIsImFycmF5Il0=='], ['bnVsbA']]);
