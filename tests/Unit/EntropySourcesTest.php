<?php

declare(strict_types=1);

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Sources\CsprngSource;

/**
 * The declarations every source makes about itself.
 *
 * `collect()` reaches across the network and is not unit-testable, but everything
 * a source *claims* is pure data — and those claims are what the /entropy page
 * shows people. A source with an empty origin, or one quietly filed under the
 * wrong class, is a page telling someone something untrue.
 *
 * @return array<string, EntropySource>
 */
function allSources(): array
{
    $sources = [];

    foreach ((require __DIR__.'/../../config/randomly.php')['entropy']['sources'] as $class) {
        $source = new $class;
        $sources[$source->key()] = $source;
    }

    return $sources;
}

it('describes itself completely', function (EntropySource $source): void {
    expect($source->key())->toMatch('/^[a-z0-9-]+$/')
        ->and($source->label())->not->toBeEmpty()
        ->and($source->origin())->not->toBeEmpty()
        ->and($source->refreshSeconds())->toBeGreaterThanOrEqual(0);

    // The origin is shown to the reader as a sentence about physics, so it has to
    // read as one rather than as a label.
    expect($source->origin())->toEndWith('.')
        ->and(strlen($source->origin()))->toBeGreaterThan(40);
})->with(allSources());

it('gives every source a distinct key', function (): void {
    $keys = array_keys(allSources());

    expect($keys)->toHaveCount(count(array_unique($keys)));
});

it('attaches a caveat to everything that is not cryptographic', function (EntropySource $source): void {
    // Class A stands alone; anything else has to carry the sentence explaining why
    // it does not. This is the honesty contract in one assertion.
    if ($source->class() === EntropyClass::Cryptographic) {
        expect($source->class()->caveat())->toBeNull();
    } else {
        expect($source->class()->caveat())->not->toBeEmpty();
    }
})->with(allSources());

it('keeps the CSPRNG available without a network', function (): void {
    // The floor under everything. If this ever needs the network, a third-party
    // outage stops being a degraded receipt and starts being a broken site.
    $material = (new CsprngSource)->collect(32);

    // strlen, not toHaveLength: that counts *characters* through mb_strlen, and
    // random bytes are not valid UTF-8 — 32 bytes measured 28 here. Any length
    // assertion on binary data has to be told it is looking at bytes.
    expect(strlen($material->bytes))->toBe(32)
        ->and((new CsprngSource)->collect(32)->bytes)->not->toBe($material->bytes)
        ->and((new CsprngSource)->refreshSeconds())->toBe(0);
});

it('lists sources strongest first', function (): void {
    // The /entropy page reads top to bottom, so the order is a claim in itself:
    // it should not open with the ISS and bury the quantum source at the bottom.
    $classes = array_map(fn (EntropySource $s): string => $s->class()->value, array_values(allSources()));
    $sorted = $classes;
    sort($sorted);

    expect($classes)->toBe($sorted);
});
