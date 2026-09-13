<?php

declare(strict_types=1);

use App\Random\Entropy\Seed;
use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Numbers\DiceGenerator;
use App\Random\Generators\Numbers\IntegersGenerator;
use App\Random\Generators\Numbers\PasswordGenerator;
use App\Random\Generators\Renderer;

/**
 * Read from the registry's own config rather than a list maintained here, so a
 * new generator inherits this whole suite the moment it ships. A hand-kept list
 * would drift, and the generators added last are exactly the ones least likely to
 * get remembered.
 *
 * @return array<string, Generator>
 */
function allGenerators(): array
{
    $generators = [];

    foreach ((require __DIR__.'/../../config/randomly.php')['generators'] as $class) {
        $generator = new $class;
        $generators[$generator->key()] = $generator;
    }

    return $generators;
}

function seedFor(string $bytes = 'randomly-fixed!!'): Seed
{
    return new Seed($bytes, stubReceipt());
}

it('declares a schema whose own defaults survive a round trip', function (Generator $generator) {
    $defaults = $generator->schema()->defaults();

    // Coercion clamps out-of-range values, so defaults surviving unchanged proves
    // no generator ships a default outside the bounds it advertises.
    expect($generator->schema()->coerce($defaults)->all())->toBe($defaults);
})->with(allGenerators());

it('produces the same result twice from the same seed', function (Generator $generator) {
    $seed = seedFor();
    $params = $generator->schema()->coerce([]);

    $first = $generator->generate($seed->rng($generator->key(), $generator->version(), $params->fingerprint()), $params);
    $second = $generator->generate($seed->rng($generator->key(), $generator->version(), $params->fingerprint()), $params);

    expect($first->display)->toBe($second->display)
        ->and($first->value)->toEqual($second->value);
})->with(allGenerators());

it('produces different results from different seeds', function (Generator $generator) {
    if (! $generator->usesEntropy()) {
        // An identicon is a pure function of its input by design; seeding it
        // differently *should* change nothing. The contract says so explicitly, so
        // the test can respect it rather than carve out a name.
        expect(true)->toBeTrue();

        return;
    }

    $params = $generator->schema()->coerce([]);

    $a = $generator->generate(seedFor('randomly-fixed!!')->rng($generator->key(), 1, $params->fingerprint()), $params);
    $b = $generator->generate(seedFor('a-different-seed')->rng($generator->key(), 1, $params->fingerprint()), $params);

    // Compare the value, not the sentence: a canvas generator's display describes
    // the parameters ("Clouds · 5 octaves") and is seed-independent on purpose.
    expect(json_encode($a->value))->not->toBe(json_encode($b->value));
})->with(allGenerators());

it('names itself consistently with its module', function (Generator $generator) {
    expect($generator->key())->toStartWith($generator->module()->value.'.')
        ->and($generator->name())->not->toBeEmpty()
        ->and($generator->tagline())->not->toBeEmpty()
        ->and($generator->version())->toBeGreaterThanOrEqual(1)
        ->and($generator->renderer())->toBeInstanceOf(Renderer::class);
})->with(allGenerators());

it('declares at least one control', function (Generator $generator) {
    expect($generator->schema()->params())->not->toBeEmpty();
})->with(allGenerators());

it('gives a deterministic generator no reason to draw entropy', function (Generator $generator) {
    // If a generator never touches the Rng, it must say so — otherwise the studio
    // spends a network round trip on a beacon and then credits it in a receipt for
    // a result that would have been identical without it.
    $params = $generator->schema()->coerce([]);
    $rng = seedFor()->rng($generator->key(), $generator->version(), $params->fingerprint());

    $generator->generate($rng, $params);

    if (! $generator->usesEntropy()) {
        expect($rng->bytesRead())->toBe(0);
    } else {
        expect($rng->bytesRead())->toBeGreaterThan(0);
    }
})->with(allGenerators());

it('never hands a secret generator a shareable seed', function () {
    // The rule that keeps a password from getting a replayable URL. If this ever
    // flips to false, every password ever generated becomes recoverable from a link.
    expect((new PasswordGenerator)->isSensitive())->toBeTrue()
        ->and((new IntegersGenerator)->isSensitive())->toBeFalse()
        ->and((new DiceGenerator)->isSensitive())->toBeFalse();
});

it('keeps integers inside the requested range', function () {
    $generator = new IntegersGenerator;
    $params = $generator->schema()->coerce(['count' => 500, 'min' => -20, 'max' => 20]);
    $result = $generator->generate(seedFor()->rng('numbers.integers', 1, $params->fingerprint()), $params);

    expect($result->value)->toHaveCount(500);
    foreach ($result->value as $n) {
        expect($n)->toBeGreaterThanOrEqual(-20)->toBeLessThanOrEqual(20);
    }
});

it('draws unique integers without repeating', function () {
    $generator = new IntegersGenerator;
    $params = $generator->schema()->coerce(['count' => 60, 'min' => 1, 'max' => 60, 'unique' => true]);
    $result = $generator->generate(seedFor()->rng('numbers.integers', 1, $params->fingerprint()), $params);

    expect(array_unique($result->value))->toHaveCount(60)
        ->and(min($result->value))->toBe(1)
        ->and(max($result->value))->toBe(60);
});

it('caps a unique draw at the size of the range instead of looping forever', function () {
    $generator = new IntegersGenerator;
    $params = $generator->schema()->coerce(['count' => 100, 'min' => 1, 'max' => 6, 'unique' => true]);
    $result = $generator->generate(seedFor()->rng('numbers.integers', 1, $params->fingerprint()), $params);

    expect($result->value)->toHaveCount(6)
        ->and($result->meta['truncated'])->toBeTrue();
});

it('swaps a reversed range rather than failing mid-slider-drag', function () {
    $generator = new IntegersGenerator;
    $params = $generator->schema()->coerce(['count' => 20, 'min' => 90, 'max' => 10]);
    $result = $generator->generate(seedFor()->rng('numbers.integers', 1, $params->fingerprint()), $params);

    foreach ($result->value as $n) {
        expect($n)->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(90);
    }
});

it('parses dice notation into the roll it describes', function (string $notation, int $dice, int $sides, int $kept) {
    $generator = new DiceGenerator;
    $params = $generator->schema()->coerce(['notation' => $notation, 'rolls' => 1]);
    $result = $generator->generate(seedFor()->rng('numbers.dice', 1, $params->fingerprint()), $params);

    expect($result->value[0]['dice'])->toHaveCount($dice)
        ->and($result->value[0]['kept'])->toHaveCount($kept);

    foreach ($result->value[0]['dice'] as $die) {
        expect($die)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($sides);
    }
})->with([
    ['3d6', 3, 6, 3],
    ['4d6kh3', 4, 6, 3],
    ['2d20kl1', 2, 20, 1],
    ['1d100', 1, 100, 1],
    ['d20', 1, 20, 1],
]);

it('applies a dice modifier to the total', function () {
    $generator = new DiceGenerator;
    $params = $generator->schema()->coerce(['notation' => '1d6+10', 'rolls' => 20]);
    $result = $generator->generate(seedFor()->rng('numbers.dice', 1, $params->fingerprint()), $params);

    foreach ($result->value as $roll) {
        expect($roll['total'])->toBe(array_sum($roll['kept']) + 10)
            ->toBeGreaterThanOrEqual(11)->toBeLessThanOrEqual(16);
    }
});

it('falls back to a plain roll when the notation is still being typed', function (string $garbage) {
    $generator = new DiceGenerator;
    $params = $generator->schema()->coerce(['notation' => $garbage, 'rolls' => 1]);
    $result = $generator->generate(seedFor()->rng('numbers.dice', 1, $params->fingerprint()), $params);

    expect($result->meta['notation'])->toBe('1d6');
})->with([['4d'], ['kh3'], [''], ['nonsense'], ['4d6kh']]);

it('builds passwords only from the selected alphabet', function () {
    $generator = new PasswordGenerator;
    $params = $generator->schema()->coerce([
        'length' => 40, 'count' => 10,
        'lowercase' => true, 'uppercase' => false, 'digits' => false, 'symbols' => false,
    ]);
    $result = $generator->generate(seedFor()->rng('numbers.password', 1, $params->fingerprint()), $params);

    foreach ($result->value as $password) {
        expect($password)->toMatch('/^[a-z]{40}$/');
    }
    expect($result->meta['alphabet_size'])->toBe(26);
});

it('still produces a password when every character set is switched off', function () {
    // A user mid-decision, not an error state: the panel should not be able to
    // put the generator into a configuration that throws.
    $generator = new PasswordGenerator;
    $params = $generator->schema()->coerce([
        'length' => 12, 'count' => 1,
        'lowercase' => false, 'uppercase' => false, 'digits' => false, 'symbols' => false,
    ]);
    $result = $generator->generate(seedFor()->rng('numbers.password', 1, $params->fingerprint()), $params);

    expect($result->value[0])->toHaveLength(12);
});

it('drops lookalike characters when asked', function () {
    $generator = new PasswordGenerator;
    $params = $generator->schema()->coerce([
        'length' => 60, 'count' => 20, 'avoid_ambiguous' => true,
    ]);
    $result = $generator->generate(seedFor()->rng('numbers.password', 1, $params->fingerprint()), $params);

    foreach ($result->value as $password) {
        expect($password)->not->toMatch('/[Il1O0o]/');
    }
});

it('reports meta the studio can actually render', function (Generator $generator): void {
    // patterns.maze returned per-algorithm statistics as a nested array, the meta
    // strip called implode() on it, and the whole studio page 500'd with "Array to
    // string conversion". The view is defensive about it now, but the shape is
    // still worth pinning: a generator describing itself thoroughly must never be
    // able to take a page down.
    $params = $generator->schema()->coerce([]);
    $result = $generator->generate(
        seedFor()->rng($generator->key(), $generator->version(), $params->fingerprint()),
        $params,
    );

    foreach ($result->meta as $key => $value) {
        expect($key)->toBeString();

        // Everything must survive the trip to JSON — it goes out over the API as
        // well as onto the page.
        expect(json_encode([$key => $value], JSON_THROW_ON_ERROR))->toBeString();

        if (is_array($value)) {
            // A list of scalars is joined for display; anything else is summarised.
            // Both paths are fine — an object or a resource is not.
            array_walk_recursive($value, function ($leaf) use ($key): void {
                expect($leaf)->not->toBeObject("meta[{$key}] contains an object, which cannot be displayed or serialised");
                expect(is_resource($leaf))->toBeFalse("meta[{$key}] contains a resource");
            });
        }
    }
})->with(allGenerators());
