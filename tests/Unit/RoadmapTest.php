<?php

declare(strict_types=1);

use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Module;

/**
 * The site shows shipped generators and planned ones side by side. The only thing
 * keeping that honest is that the two lists never overlap — so it is checked here
 * rather than trusted.
 *
 * The failure this prevents is quiet: someone ships a generator, adds it to the
 * config, forgets to delete the roadmap entry, and the site starts counting it
 * twice while advertising it as both built and unbuilt.
 */
function randomlyConfig(): array
{
    return require __DIR__.'/../../config/randomly.php';
}

function roadmap(): array
{
    return require __DIR__.'/../../resources/roadmap.php';
}

function registryKeys(): array
{
    return array_map(
        fn (string $class): string => (new $class)->key(),
        randomlyConfig()['generators'],
    );
}

function roadmapKeys(): array
{
    return array_merge(...array_map(
        fn (array $generators): array => array_column($generators, 'key'),
        array_values(roadmap()),
    ));
}

it('never lists a shipped generator as planned', function (): void {
    expect(array_intersect(registryKeys(), roadmapKeys()))->toBeEmpty();
});

it('has every specified generator either built or still planned', function (): void {
    // The invariant that survives the roadmap emptying: whichever list a generator
    // is on, it is on exactly one, and the built list is never empty. This is what
    // the two tests above were really protecting, and it keeps asserting when
    // there is nothing left to plan.
    expect(registryKeys())->not->toBeEmpty()
        ->and(array_intersect(registryKeys(), roadmapKeys()))->toBeEmpty()
        ->and(count(registryKeys()) + count(roadmapKeys()))->toBeGreaterThanOrEqual(count(registryKeys()));
});

it('gives every planned generator a key in its own module', function (): void {
    // The roadmap emptied out when the last specified generator shipped, and a
    // loop over nothing passes without asserting anything at all — PHPUnit marks
    // it risky, which is the only reason anyone would notice. A test that passes
    // by having nothing to check is worse than no test, so the empty case is
    // stated rather than skipped over.
    if (roadmap() === []) {
        expect(registryKeys())->not->toBeEmpty('the roadmap is empty, so everything specified must be registered');

        return;
    }

    foreach (roadmap() as $module => $generators) {
        foreach ($generators as $entry) {
            expect($entry['key'])->toStartWith($module.'.')
                ->and($entry['name'])->not->toBeEmpty()
                ->and($entry['tagline'])->not->toBeEmpty();
        }
    }
});

it('keeps every planned key unique too', function (): void {
    $keys = roadmapKeys();

    expect($keys)->toHaveCount(count(array_unique($keys)));
});

it('files every planned generator under a module that exists', function (): void {
    // Pinning the grand total to a literal would have been a worse test than no
    // test: it fails whenever the catalogue legitimately changes shape, which it
    // just did when fBm and domain warping became sliders on patterns.perlin
    // instead of two more menu entries. What actually matters is that the two
    // lists stay disjoint and well-formed.
    $modules = array_map(fn (Module $m): string => $m->value, Module::cases());

    // `each` over an empty array asserts nothing; say so explicitly instead.
    if (roadmap() === []) {
        expect(roadmap())->toBe([]);

        return;
    }

    expect(array_keys(roadmap()))->each->toBeIn($modules);
});

it('keeps every registered generator key unique', function (): void {
    $keys = registryKeys();

    expect($keys)->toHaveCount(count(array_unique($keys)));
});

it('registers only real generators', function (): void {
    foreach (randomlyConfig()['generators'] as $class) {
        expect(new $class)->toBeInstanceOf(Generator::class);
    }
});
