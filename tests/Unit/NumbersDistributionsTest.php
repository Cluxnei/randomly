<?php

declare(strict_types=1);

use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Numbers\CoordinatesGenerator;
use App\Random\Generators\Numbers\GaussianGenerator;
use App\Random\Generators\Numbers\LotteryGenerator;
use App\Random\Generators\Numbers\UuidGenerator;

/**
 * The numbers generators that make a claim about a *distribution*, rather than
 * about a range.
 *
 * Every test here runs against a fixed seed, so none of them is statistical in the
 * flaky sense: the χ² values below are known answers, not samples, and a failure
 * means the arithmetic changed rather than that the dice were unkind. What makes
 * them worth writing is that these are the generators whose bugs are invisible.
 * A broken sphere sampler returns perfectly plausible latitudes. A Box–Muller with
 * a sign error returns perfectly plausible numbers. Only the shape gives them away.
 */
function drawFrom(Generator $generator, array $input = [], string $bytes = 'randomly-fixed!!'): array
{
    $params = $generator->schema()->coerce($input);
    $result = $generator->generate(
        seedFor($bytes)->rng($generator->key(), $generator->version(), $params->fingerprint()),
        $params,
    );

    return [$result->value, $result->meta, $result->display];
}

/**
 * Pearson's χ² of a sample against a uniform distribution over [lo, hi].
 *
 * Ten bins, so nine degrees of freedom: the critical value at α = 0.001 is 27.88.
 * Below that there is no evidence against uniformity; far above it there is
 * overwhelming evidence.
 */
function chiSquaredAgainstUniform(array $values, float $lo, float $hi, int $bins = 10): float
{
    $counts = array_fill(0, $bins, 0);

    foreach ($values as $value) {
        $bin = (int) floor((($value - $lo) / ($hi - $lo)) * $bins);
        $counts[max(0, min($bins - 1, $bin))]++;
    }

    $expected = count($values) / $bins;
    $chi = 0.0;

    foreach ($counts as $observed) {
        $chi += (($observed - $expected) ** 2) / $expected;
    }

    return $chi;
}

// ── numbers.coordinates ──────────────────────────────────────────────────────

it('samples the sphere so that latitude is not uniform, and sin(latitude) is', function (): void {
    /*
     * The whole generator in one assertion.
     *
     * Uniform *area* on a sphere means uniform in sin(lat), which means latitude
     * itself must be bunched towards the equator — so a χ² of the correct method's
     * latitudes against uniform has to reject, and reject enormously. Meanwhile
     * sin of those same latitudes must not reject at all.
     *
     * If someone ever "fixes" this generator by sampling latitude uniformly, the
     * first expectation fails immediately. That is the point of writing it this way
     * round rather than asserting the formula: the formula could be transcribed
     * correctly into the wrong place.
     */
    [$value] = drawFrom(new CoordinatesGenerator, ['count' => 1000]);

    $correct = array_column($value['correct'], 'lat');
    $sines = array_map(fn (float $lat): float => sin(deg2rad($lat)), $correct);

    expect(chiSquaredAgainstUniform($correct, -90, 90))->toBeGreaterThan(100.0)
        ->and(chiSquaredAgainstUniform($sines, -1, 1))->toBeLessThan(27.88);
});

it('shows the bug beside it, with latitude uniform exactly as the bug produces', function (): void {
    [$value] = drawFrom(new CoordinatesGenerator, ['count' => 1000]);

    $naive = array_column($value['naive'], 'lat');

    expect(chiSquaredAgainstUniform($naive, -90, 90))->toBeLessThan(27.88)
        // Longitude takes no correction under either method — meridians are all
        // the same length — so it stays uniform in both sets.
        ->and(chiSquaredAgainstUniform(array_column($value['correct'], 'lon'), -180, 180))->toBeLessThan(27.88);
});

it('puts far fewer correct points in the polar caps than the naive method does', function (): void {
    [, $meta] = drawFrom(new CoordinatesGenerator, ['count' => 1000]);

    // 1 − sin 60° = 0.134 of the Earth's surface lies above 60°, against a third
    // of the latitude range. The naive method should land near the third.
    expect($meta['polar_share_expected'])->toBe(0.134)
        ->and($meta['polar_share_correct'])->toBeGreaterThan(0.10)->toBeLessThan(0.17)
        ->and($meta['polar_share_naive'])->toBeGreaterThan(0.29)->toBeLessThan(0.38);
});

it('pairs the two methods point for point so only the transform differs', function (): void {
    [$value] = drawFrom(new CoordinatesGenerator, ['count' => 50]);

    foreach ($value['correct'] as $i => $point) {
        expect($point['lon'])->toBe($value['naive'][$i]['lon'])
            ->and($point['lat'])->toBeGreaterThanOrEqual(-90.0)->toBeLessThanOrEqual(90.0);
    }
});

// ── numbers.gaussian ─────────────────────────────────────────────────────────

it('lands on the mean and standard deviation it was asked for', function (): void {
    [$values, $meta] = drawFrom(new GaussianGenerator, ['count' => 4000, 'mu' => 10.0, 'sigma' => 3.0]);

    // The standard error of the mean here is 3/√4000 = 0.047, so a tolerance of
    // 0.25 is five of them: wide enough never to be unlucky, tight enough that a
    // Box–Muller with the wrong constant cannot hide inside it.
    expect($values)->toHaveCount(4000)
        ->and($meta['observed_mean'])->toBeGreaterThan(9.75)->toBeLessThan(10.25)
        ->and($meta['observed_sigma'])->toBeGreaterThan(2.8)->toBeLessThan(3.2)
        ->and($meta['standard_error'])->toBe(0.047);
});

it('reproduces the 68-95-99.7 rule without being told it', function (): void {
    [, $meta] = drawFrom(new GaussianGenerator, ['count' => 4000]);

    expect($meta['within_sigma']['1'])->toBeGreaterThan(0.65)->toBeLessThan(0.72)
        ->and($meta['within_sigma']['2'])->toBeGreaterThan(0.94)->toBeLessThan(0.97)
        ->and($meta['within_sigma']['3'])->toBeGreaterThan(0.99);
});

it('bins every value it emits into the histogram it reports', function (): void {
    [$values, $meta] = drawFrom(new GaussianGenerator, ['count' => 800, 'bins' => 16]);

    expect($meta['histogram']['counts'])->toHaveCount(16)
        ->and(array_sum($meta['histogram']['counts']))->toBe(count($values))
        // The expected curve is n × density × bin width summed across ±4σ, which
        // is 99.99% of the distribution — so it should very nearly total n.
        ->and(array_sum($meta['histogram']['expected']))->toBeGreaterThan(799.0)
        ->and(array_sum($meta['histogram']['expected']))->toBeLessThan(800.1);
});

it('truncates by redrawing rather than by clipping', function (): void {
    [$values, $meta] = drawFrom(new GaussianGenerator, ['count' => 600, 'clamp' => true, 'clamp_sigma' => 1.5]);

    foreach ($values as $value) {
        expect(abs($value))->toBeLessThanOrEqual(1.5);
    }

    // Clipping would have produced a pile of values sitting exactly on ±1.5σ.
    // Rejection produces redraws instead, and at 1.5σ about 13% of draws fall
    // outside — so there must be a substantial number of them.
    expect($meta['redraws'])->toBeGreaterThan(50)
        ->and(array_count_values(array_map('strval', $values))['1.5'] ?? 0)->toBeLessThan(3);
});

// ── numbers.lottery ──────────────────────────────────────────────────────────

it('states the true odds of every game it ships', function (string $game, int $odds): void {
    [, $meta] = drawFrom(new LotteryGenerator, ['game' => $game, 'lines' => 1]);

    expect($meta['odds_one_in'])->toBe($odds);
})->with([
    // C(60,6) = 50,063,860.
    ['mega-sena', 50_063_860],
    // C(69,5) × C(26,1) = 11,238,513 × 26.
    ['powerball', 292_201_338],
    // C(50,5) × C(12,2) = 2,118,760 × 66.
    ['euromillions', 139_838_160],
]);

it('computes C(n, k) exactly against an independent factorial', function (int $n, int $k, int $expected): void {
    // The generator's own combinations() divides at every step to keep the
    // intermediate small. This checks it against the textbook definition, computed
    // in floats, for sizes where a double is still exact.
    [, $meta] = drawFrom(new LotteryGenerator, [
        'game' => 'custom', 'lines' => 1, 'pool' => $n, 'picks' => $k, 'bonus_pool' => 0, 'bonus_picks' => 0,
    ]);

    $factorial = fn (int $m): float => array_product(range(1, max(1, $m)));
    $textbook = $factorial($n) / ($factorial($k) * $factorial($n - $k));

    // Compared as a ratio, not for equality: 60! in a double carries a relative
    // error around 1e-16, which is several units of absolute error by the time it
    // has been divided back down. That the two agree to nine figures is the claim.
    expect($meta['odds_one_in'])->toBe($expected)
        ->and(abs($meta['odds_one_in'] - $textbook) / $textbook)->toBeLessThan(1e-9);
})->with([
    [49, 6, 13_983_816],
    [60, 6, 50_063_860],
    [10, 3, 120],
    [20, 10, 184_756],
]);

it('draws every line without replacement and inside the pool', function (): void {
    [$lines] = drawFrom(new LotteryGenerator, ['game' => 'euromillions', 'lines' => 20]);

    expect($lines)->toHaveCount(20);

    foreach ($lines as $line) {
        expect($line['numbers'])->toHaveCount(5)
            ->and(array_unique($line['numbers']))->toHaveCount(5)
            ->and($line['bonus'])->toHaveCount(2)
            ->and(array_unique($line['bonus']))->toHaveCount(2);

        foreach ($line['numbers'] as $n) {
            expect($n)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(50);
        }

        foreach ($line['bonus'] as $n) {
            expect($n)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(12);
        }
    }
});

it('divides the odds by the number of lines bought and no more', function (): void {
    [, $meta] = drawFrom(new LotteryGenerator, ['game' => 'mega-sena', 'lines' => 10]);

    expect($meta['odds_for_these_lines'])->toBe('1 in '.number_format((int) round(50_063_860 / 10)))
        ->and($meta['odds_one_in'])->toBe(50_063_860);
});

// ── numbers.uuid ─────────────────────────────────────────────────────────────

it('never offers a replayable link for an identifier', function (): void {
    // Same rule that keeps a password off a permalink. An identifier derived from
    // a public seed is guessable by anyone holding the link, which is the one
    // property an identifier is usually bought for.
    expect((new UuidGenerator)->isSensitive())->toBeTrue();
});

it('lays out a v4 exactly as RFC 9562 specifies', function (): void {
    [$ids] = drawFrom(new UuidGenerator, ['flavour' => 'v4', 'count' => 60]);

    foreach ($ids as $id) {
        // Version nibble 4 at position 13, variant nibble 8/9/a/b at 17.
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    }

    expect(array_unique($ids))->toHaveCount(60);
});

it('lays out a v7 with the version bits set and the timestamp in front', function (): void {
    [$ids, $meta] = drawFrom(new UuidGenerator, [
        'flavour' => 'v7', 'count' => 40, 'moment' => '2026-03-01T12:00:00Z', 'spread' => 900,
    ]);

    $expected = gmmktime(12, 0, 0, 3, 1, 2026) * 1000;

    foreach ($ids as $id) {
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

        // The first 48 bits are the millisecond, big-endian, and nothing else.
        $ms = hexdec(substr(str_replace('-', '', $id), 0, 12));
        expect($ms)->toBeGreaterThanOrEqual($expected)
            ->toBeLessThanOrEqual($expected + 40 * 900);
    }

    expect($meta['timestamp_prefix'])->toBe(substr($ids[0], 0, 13));
});

it('keeps v7 and ULID sortable by the time they carry', function (string $flavour): void {
    [$ids] = drawFrom(new UuidGenerator, ['flavour' => $flavour, 'count' => 100, 'spread' => 5000]);

    $sorted = $ids;
    sort($sorted, SORT_STRING);

    // Plain lexicographic order, which is the promise both formats make and the
    // reason either exists: a database index built on one appends rather than
    // scattering. It holds only because the clock is strictly increasing here.
    expect($sorted)->toBe($ids);
})->with(['v7', 'ulid']);

it('writes a ULID as 26 Crockford characters with the clock in the first ten', function (): void {
    [$ids, $meta] = drawFrom(new UuidGenerator, ['flavour' => 'ulid', 'count' => 30, 'moment' => '2026-01-01T00:00:00Z']);

    foreach ($ids as $id) {
        expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
        // The sixth character of a ULID advances about once every seventeen
        // minutes — 32^4 milliseconds — so everything drawn within a few seconds of
        // the same moment shares it. That shared prefix is precisely what the
        // format leaks: it is the clock, written down in base 32.
        expect(substr($id, 0, 6))->toBe(substr($ids[0], 0, 6));
    }

    expect($meta['first_moment'])->toBe('2026-01-01T00:00:00.000Z');
});

it('builds a NanoID from 64 symbols and nothing else', function (): void {
    [$ids, $meta] = drawFrom(new UuidGenerator, ['flavour' => 'nanoid', 'count' => 40, 'size' => 24]);

    foreach ($ids as $id) {
        expect($id)->toMatch('/^[A-Za-z0-9_-]{24}$/');
    }

    // 64 symbols is exactly six bits each, with no rejection and no modulo bias.
    expect($meta['random_bits_each'])->toBe(144.0);
});

it('reads the clock out of a parameter and never out of the wall', function (): void {
    // The purity rule, tested where it is easiest to break. A generator that
    // consulted time() would produce different identifiers on a second run, and
    // every permalink and every replay in the project rests on it not doing that.
    [$first] = drawFrom(new UuidGenerator, ['flavour' => 'v7', 'count' => 5]);
    [$second] = drawFrom(new UuidGenerator, ['flavour' => 'v7', 'count' => 5]);

    expect($first)->toBe($second);

    [$later] = drawFrom(new UuidGenerator, ['flavour' => 'v7', 'count' => 5, 'moment' => '2030-06-15T08:30:00Z']);

    expect($later[0])->not->toBe($first[0]);
});

it('falls back to a fixed moment rather than a clock when the date is unparseable', function (string $garbage): void {
    [, $meta] = drawFrom(new UuidGenerator, ['flavour' => 'ulid', 'count' => 2, 'moment' => $garbage]);

    expect($meta['first_moment'])->toBe('2026-01-01T00:00:00.000Z');
})->with([['now'], ['tomorrow'], [''], ['2026-13-45'], ['not a date']]);
