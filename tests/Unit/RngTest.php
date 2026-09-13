<?php

declare(strict_types=1);

use App\Random\Rng\HkdfStream;
use App\Random\Rng\Rng;

/** Every test here draws from a fixed stream, so nothing below can flake. */
function rngFor(string $info = 'randomly/test'): Rng
{
    return new Rng(new HkdfStream('a fixed 16-byte!!', $info));
}

it('replays the same draws from the same stream', function (): void {
    $one = rngFor();
    $two = rngFor();

    $drawsFrom = fn (Rng $rng): array => array_map(fn (): array => [
        $rng->intBetween(1, 1000),
        $rng->float(),
        $rng->bool(),
        $rng->gaussian(),
    ], range(1, 50));

    expect($drawsFrom($one))->toBe($drawsFrom($two));
});

it('draws a different sequence from a different stream', function (): void {
    $a = rngFor('randomly/test|a');
    $b = rngFor('randomly/test|b');

    expect(array_map(fn (): int => $a->uint32(), range(1, 32)))
        ->not->toBe(array_map(fn (): int => $b->uint32(), range(1, 32)));
});

it('keeps float() inside [0, 1)', function (): void {
    $rng = rngFor();

    for ($i = 0; $i < 10_000; $i++) {
        $f = $rng->float();
        expect($f)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
    }
});

it('keeps intBetween() inside its bounds and reaches both ends', function (): void {
    $rng = rngFor();
    $seen = [];

    for ($i = 0; $i < 10_000; $i++) {
        $v = $rng->intBetween(-5, 17);
        expect($v)->toBeGreaterThanOrEqual(-5)->toBeLessThanOrEqual(17);
        $seen[$v] = true;
    }

    expect($seen)->toHaveKey(-5)->toHaveKey(17);
});

it('returns the only value in a single-element range', function (): void {
    expect(rngFor()->intBetween(5, 5))->toBe(5);
});

it('rejects a single-element range that is empty', function (): void {
    expect(fn () => rngFor()->intBetween(9, 2))->toThrow(InvalidArgumentException::class);
});

it('rolls a die without visible bias', function (): void {
    $rng = rngFor();
    $draws = 60_000;
    $counts = array_fill(1, 6, 0);

    for ($i = 0; $i < $draws; $i++) {
        $counts[$rng->intBetween(1, 6)]++;
    }

    $expected = $draws / 6;
    $chi2 = array_sum(array_map(fn (int $c): float => (($c - $expected) ** 2) / $expected, $counts));

    // 11.07 is the 95th percentile for 5 degrees of freedom; 50 is absurdly
    // generous on purpose, because a fixed seed means this only ever has to
    // catch a genuinely broken sampler, never a bad day.
    expect($chi2)->toBeLessThan(50.0);
});

it('shuffles into a true permutation', function (): void {
    $items = range(1, 100);
    $shuffled = rngFor()->shuffle($items);

    $sorted = $shuffled;
    sort($sorted);

    expect($shuffled)->not->toBe($items)
        ->and($sorted)->toBe($items);
});

it('samples k distinct items', function (): void {
    $items = range(1, 100);
    $sample = rngFor()->sample($items, 10);

    expect($sample)->toHaveCount(10)
        ->and(array_unique($sample))->toHaveCount(10);

    foreach ($sample as $value) {
        expect($items)->toContain($value);
    }
});

it('refuses to sample more distinct items than exist', function (): void {
    expect(fn () => rngFor()->sample([1, 2, 3], 4))->toThrow(InvalidArgumentException::class);
});

it('draws a standard normal with the right mean and spread', function (): void {
    $rng = rngFor();
    $draws = 20_000;
    $sum = 0.0;
    $sumSquares = 0.0;

    for ($i = 0; $i < $draws; $i++) {
        $z = $rng->gaussian();
        $sum += $z;
        $sumSquares += $z * $z;
    }

    $mean = $sum / $draws;
    $sd = sqrt($sumSquares / $draws - $mean ** 2);

    // The standard error here is ~0.007, so 0.05 is several sigma of headroom.
    expect(abs($mean))->toBeLessThan(0.05)
        ->and(abs($sd - 1.0))->toBeLessThan(0.05);
});

it('actually runs rejection sampling on a range that needs it', function (): void {
    // 1..100 needs 7 bits, which covers 0..127, so roughly a fifth of draws overshoot.
    $rng = rngFor();

    for ($i = 0; $i < 1_000; $i++) {
        $rng->intBetween(1, 100);
    }

    expect($rng->rejections())->toBeGreaterThan(0);
});

it('never rejects a range that fills its bit width exactly', function (): void {
    $rng = rngFor();

    for ($i = 0; $i < 1_000; $i++) {
        $rng->intBetween(0, 255);
    }

    expect($rng->rejections())->toBe(0);
});
