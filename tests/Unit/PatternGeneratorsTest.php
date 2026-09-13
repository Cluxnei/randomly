<?php

declare(strict_types=1);

use App\Random\Entropy\Seed;
use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Patterns\AutomatonGenerator;
use App\Random\Generators\Patterns\PerlinGenerator;
use App\Random\Generators\Patterns\ReactionGenerator;
use App\Random\Generators\Patterns\TruchetGenerator;
use App\Random\Generators\Patterns\WorleyGenerator;
use App\Random\Generators\Renderer;

/**
 * The canvas generators are tested from a different angle than the text ones.
 *
 * Nothing here asserts anything about pixels — the drawing lives in JavaScript
 * and is checked by looking at it. What PHP owns is the *spec*: the parameters
 * resolved, the palette drawn, the offsets and seeds that make two canvases with
 * identical sliders differ. So that is what is under test, plus the handful of
 * places where a generator has a genuine known answer.
 */
function patternGenerators(): array
{
    return [
        'perlin' => new PerlinGenerator,
        'worley' => new WorleyGenerator,
        'automaton' => new AutomatonGenerator,
        'reaction' => new ReactionGenerator,
        'truchet' => new TruchetGenerator,
    ];
}

function patternSeed(string $bytes = 'randomly-canvas!'): Seed
{
    return new Seed($bytes, stubReceipt());
}

function generateSpec(Generator $generator, array $input = [], string $bytes = 'randomly-canvas!'): array
{
    $params = $generator->schema()->coerce($input);

    return $generator->generate(
        patternSeed($bytes)->rng($generator->key(), $generator->version(), $params->fingerprint()),
        $params,
    )->value;
}

it('declares a schema whose own defaults survive a round trip', function (Generator $generator) {
    $defaults = $generator->schema()->defaults();

    expect($generator->schema()->coerce($defaults)->all())->toBe($defaults);
})->with(patternGenerators());

it('draws on the canvas and says so', function (Generator $generator) {
    expect($generator->renderer())->toBe(Renderer::Canvas)
        ->and($generator->key())->toStartWith('patterns.')
        ->and($generator->isSensitive())->toBeFalse();
})->with(patternGenerators());

it('emits a spec small enough to be worth emitting', function (Generator $generator) {
    $spec = generateSpec($generator);

    // The whole architecture rests on this number. If a generator ever starts
    // shipping the field instead of the recipe for it, the response stops being
    // a few hundred bytes and the slider stops being instant.
    expect(strlen(json_encode($spec)))->toBeLessThan(4096)
        ->and($spec['algorithm'])->toBeString()
        ->and($spec['palette'])->each->toMatch('/^#[0-9a-f]{6}$/');
})->with(patternGenerators());

it('produces the same spec twice from the same seed', function (Generator $generator) {
    expect(generateSpec($generator))->toEqual(generateSpec($generator));
})->with(patternGenerators());

it('produces a different spec from a different seed', function (Generator $generator) {
    // Not display, which for a canvas generator is a sentence about the sliders
    // and is identical across seeds by design. What has to move is the palette
    // and the offsets — the compositional choices the Rng is actually for.
    expect(generateSpec($generator))->not->toEqual(generateSpec($generator, bytes: 'a-different-key!'));
})->with(patternGenerators());

it('has a browser renderer registered for the algorithm it names', function (Generator $generator) {
    // The failure this catches is a generator that ships without its other half:
    // the studio would render an empty canvas and throw "no client renderer",
    // which is a runtime error nothing else in the suite would see.
    $algorithm = generateSpec($generator)['algorithm'];

    // __DIR__ rather than base_path(): these are unit tests and never boot the
    // container.
    $resources = __DIR__.'/../../resources/js';

    expect("{$resources}/patterns/{$algorithm}.js")->toBeFile()
        ->and(file_get_contents("{$resources}/canvas.js"))
        ->toContain("patterns/{$algorithm}.js");
})->with(patternGenerators());

it('expands a rule number into its eight-entry lookup table', function (int $rule, array $table) {
    // The one place this module has a genuine known answer. Rule 90 is the
    // Sierpiński rule and must be [0,1,0,1,1,0,1,0]; rule 30 is the chaotic one
    // Mathematica shipped; 0 and 255 are the two constant rules.
    expect(AutomatonGenerator::table($rule))->toBe($table);
})->with([
    [0, [0, 0, 0, 0, 0, 0, 0, 0]],
    [30, [0, 1, 1, 1, 1, 0, 0, 0]],
    [90, [0, 1, 0, 1, 1, 0, 1, 0]],
    [110, [0, 1, 1, 1, 0, 1, 1, 0]],
    [150, [0, 1, 1, 0, 1, 0, 0, 1]],
    [255, [1, 1, 1, 1, 1, 1, 1, 1]],
]);

it('makes rule 90 the XOR of a cell\'s two neighbours', function () {
    // The structural statement behind the known answer above: rule 90 ignores the
    // centre cell entirely, which is exactly why a single live cell grows into
    // Pascal's triangle mod 2. Rule 150 is the same with the centre included.
    foreach (AutomatonGenerator::table(90) as $neighbourhood => $next) {
        expect($next)->toBe((($neighbourhood >> 2) & 1) ^ ($neighbourhood & 1));
    }

    foreach (AutomatonGenerator::table(150) as $neighbourhood => $next) {
        expect($next)->toBe((($neighbourhood >> 2) & 1) ^ (($neighbourhood >> 1) & 1) ^ ($neighbourhood & 1));
    }
});

it('recovers the rule number from its own table', function (int $rule) {
    $recovered = 0;
    foreach (AutomatonGenerator::table($rule) as $i => $bit) {
        $recovered |= $bit << $i;
    }

    expect($recovered)->toBe($rule);
})->with([0, 1, 30, 73, 90, 110, 150, 184, 254, 255]);

it('lets a named automaton preset override the rule slider', function (string $preset, int $rule) {
    expect(generateSpec(new AutomatonGenerator, ['preset' => $preset, 'rule' => 7])['rule'])->toBe($rule);
})->with([
    ['r30', 30],
    ['r90', 90],
    ['r110', 110],
    ['custom', 7],
]);

it('gives the automaton exactly two colours', function () {
    // Cells are alive or dead. A third colour would imply a state that does not
    // exist, and a gradient would imply a field.
    expect(generateSpec(new AutomatonGenerator)['palette'])->toHaveCount(2);
});

it('ships the named reaction presets at their documented rates', function (string $preset, float $feed, float $kill) {
    $spec = generateSpec(new ReactionGenerator, ['preset' => $preset]);

    expect($spec['feed'])->toBe($feed)->and($spec['kill'])->toBe($kill);
})->with([
    ['mitosis', 0.035, 0.065],
    ['coral', 0.055, 0.062],
    ['solitons', 0.030, 0.062],
    // Not docs/07's 0.050: that pair decays to a uniform field and renders mud.
    // See the comment on ReactionGenerator::PRESETS.
    ['labyrinth', 0.025, 0.055],
    ['spots', 0.014, 0.054],
]);

it('falls back to the sliders when the reaction preset is custom', function () {
    $spec = generateSpec(new ReactionGenerator, ['preset' => 'custom', 'feed' => 0.042, 'kill' => 0.058]);

    expect($spec['feed'])->toBe(0.042)->and($spec['kill'])->toBe(0.058);
});

it('caps the reaction against a fixed update budget', function () {
    // The guard that stops a slider drag from locking the tab. 288² at 12,000
    // steps is a billion cell updates; what ships has to be the capped number,
    // and the receipt has to admit the cap happened.
    $generator = new ReactionGenerator;
    $params = $generator->schema()->coerce(['grid' => 288, 'steps' => 12000]);
    $result = $generator->generate(patternSeed()->rng($generator->key(), 1, $params->fingerprint()), $params);

    expect($result->value['steps'])->toBeLessThan(12000)
        ->and($result->meta['steps_requested'])->toBe(12000)
        ->and($result->meta['steps_run'])->toBe($result->value['steps'])
        ->and($result->meta['cell_updates'])->toBeLessThanOrEqual(140_000_000);
});

it('leaves a small reaction grid running for every step asked of it', function () {
    expect(generateSpec(new ReactionGenerator, ['grid' => 96, 'steps' => 4000])['steps'])->toBe(4000);
});

it('seeds the reaction with one patch per requested perturbation', function () {
    $spec = generateSpec(new ReactionGenerator, ['seeds' => 9]);

    expect($spec['seeds'])->toHaveCount(9);

    foreach ($spec['seeds'] as [$x, $y, $r]) {
        // Normalised, so the grid slider can move without reseeding the pattern.
        expect($x)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0)
            ->and($y)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0)
            ->and($r)->toBeGreaterThan(0.0)->toBeLessThan(0.1);
    }
});

it('reports the Minkowski exponent each Worley metric is a case of', function (string $metric, float|string $p) {
    $generator = new WorleyGenerator;
    $params = $generator->schema()->coerce(['metric' => $metric, 'p' => 3.5]);
    $result = $generator->generate(patternSeed()->rng($generator->key(), 1, $params->fingerprint()), $params);

    expect($result->meta['effective_p'])->toBe($p);
})->with([
    ['euclidean', 2.0],
    ['manhattan', 1.0],
    ['chebyshev', 'inf'],
    ['minkowski', 3.5],
]);

it('keeps the Worley feature and metric choices in the spec', function () {
    $spec = generateSpec(new WorleyGenerator, ['feature' => 'f1', 'metric' => 'chebyshev', 'jitter' => 0.0]);

    expect($spec['feature'])->toBe('f1')
        ->and($spec['metric'])->toBe('chebyshev')
        ->and($spec['jitter'])->toBe(0.0);
});

it('counts the Truchet tiles the canvas will actually hold', function () {
    $generator = new TruchetGenerator;
    $params = $generator->schema()->coerce(['width' => 800, 'height' => 400, 'tiles' => 8]);
    $result = $generator->generate(patternSeed()->rng($generator->key(), 1, $params->fingerprint()), $params);

    // Tiles are square and counted across the longer edge, so 8 across at 800×400
    // is an 8×4 grid — not 8×8.
    expect($result->meta['tile_count'])->toBe(32)
        ->and($result->meta['arrangements'])->toMatch('/^10\^\d+$/');
});

it('clamps every canvas generator to a size a browser can hold', function (Generator $generator) {
    $spec = generateSpec($generator, ['width' => 99999, 'height' => -5]);

    expect($spec['width'])->toBe(2048)->and($spec['height'])->toBe(200);
})->with(patternGenerators());
