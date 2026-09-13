<?php

declare(strict_types=1);

use App\Random\Generators\Params;

/**
 * The seam between fetching and generating.
 *
 * Pulling external material into a generator is where purity usually dies: the
 * fetch leaks into generate(), determinism goes with it, and the tests that would
 * have caught it now need a network. These pin the two properties that keep the
 * separation honest.
 */
it('keeps fetched data out of the seed fingerprint', function (): void {
    // The property replay depends on. A Wikipedia pool that shifts between two
    // requests must not change which stream the seed derives, or a permalink
    // would break for a reason nobody could observe.
    $params = new Params(['count' => 3, 'source' => 'wikipedia']);

    $withOne = $params->withData(['pool' => ['Alpha', 'Beta']]);
    $withAnother = $params->withData(['pool' => ['Gamma', 'Delta', 'Epsilon']]);

    expect($withOne->fingerprint())->toBe($withAnother->fingerprint())
        ->and($withOne->fingerprint())->toBe($params->fingerprint());
});

it('keeps values and data in separate compartments', function (): void {
    $params = (new Params(['count' => 3]))->withData(['pool' => ['a', 'b']]);

    expect($params->int('count'))->toBe(3)
        ->and($params->data('pool'))->toBe(['a', 'b'])
        ->and($params->all())->toBe(['count' => 3])
        ->and($params->get('pool'))->toBeNull();
});

it('reports no data when none was attached', function (): void {
    $params = new Params(['count' => 3]);

    expect($params->data())->toBe([])
        ->and($params->data('pool'))->toBeNull()
        ->and($params->data('pool', ['fallback']))->toBe(['fallback']);
});
