<?php

declare(strict_types=1);

use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Numbers\CoordinatesGenerator;
use App\Random\Generators\Numbers\DistributionGenerator;
use App\Random\Generators\Numbers\GaussianGenerator;
use App\Random\Generators\Numbers\LotteryGenerator;
use App\Random\Generators\Numbers\UuidGenerator;
use App\Random\Numbers\Distributions;

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

// ── numbers.distribution ─────────────────────────────────────────────────────

/**
 * Pearson's χ² of a discrete sample against its own analytic PMF.
 *
 * Bins with an expected count under five are pooled into their neighbour, which
 * is the standard rule: the χ² approximation to the sampling distribution breaks
 * down in the tail, and a bin expecting 0.3 events contributes enormous spurious
 * statistic the moment it sees one.
 *
 * @return array{0: float, 1: int} the statistic and its degrees of freedom
 */
function chiSquaredAgainstPmf(array $values, Closure $pmf): array
{
    $counts = array_count_values(array_map('intval', $values));
    ksort($counts);

    $n = count($values);
    $chi = 0.0;
    $bins = 0;
    $pooledObserved = 0;
    $pooledExpected = 0.0;

    for ($k = min(array_keys($counts)); $k <= max(array_keys($counts)); $k++) {
        $expected = $n * $pmf((float) $k);
        $observed = $counts[$k] ?? 0;

        $pooledObserved += $observed;
        $pooledExpected += $expected;

        if ($pooledExpected < 5) {
            continue;
        }

        $chi += (($pooledObserved - $pooledExpected) ** 2) / $pooledExpected;
        $bins++;
        $pooledObserved = 0;
        $pooledExpected = 0.0;
    }

    // Degrees of freedom is bins − 1: the total is fixed by construction, so one
    // is spent. No parameters are estimated from the data here — they came from
    // the panel — so nothing further is subtracted.
    return [$chi, max(1, $bins - 1)];
}

it('matches the analytic mean and variance of every distribution it offers', function (string $name, array $input, float $mean, ?float $variance): void {
    /*
     * The test that makes a sampler checkable at all.
     *
     * A broken Poisson returns plausible small integers and a broken Gamma
     * returns plausible positive reals; nothing about either *looks* wrong. What
     * cannot be faked is the first two moments, and every distribution here knows
     * its own analytically. Five thousand draws at a fixed seed, so this is a
     * known answer rather than a sample — a failure means the arithmetic changed.
     */
    [, $meta] = drawFrom(new DistributionGenerator, ['count' => 5000, 'distribution' => $name, 'decimals' => 6, ...$input]);

    expect($meta['expected_mean'])->toEqualWithDelta($mean, 0.001);

    // Three percent of the true mean. The standard error of a mean over 5,000
    // draws is σ/√5000, which for every distribution in this table is well inside
    // that; tightening it further would buy flakiness rather than correctness.
    expect($meta['observed_mean'])->toEqualWithDelta($mean, max(0.05, abs($mean) * 0.03));

    if ($variance !== null) {
        expect($meta['expected_variance'])->toEqualWithDelta($variance, 0.001)
            // Variance is a fourth-moment estimate and converges far more slowly
            // than the mean — 12% rather than 3%, and Pareto is excluded from the
            // check entirely below because its fourth moment is infinite at α = 3.
            ->and($meta['observed_variance'])->toEqualWithDelta($variance, $variance * 0.12);
    }
})->with([
    'poisson (Knuth)' => ['poisson', ['lambda' => 4.0], 4.0, 4.0],
    'poisson (PTRS)' => ['poisson', ['lambda' => 60.0], 60.0, 60.0],
    'binomial' => ['binomial', ['trials' => 40, 'p' => 0.3], 12.0, 8.4],
    'binomial (reflected)' => ['binomial', ['trials' => 40, 'p' => 0.85], 34.0, 5.1],
    'exponential' => ['exponential', ['lambda' => 2.0], 0.5, 0.25],
    'gamma' => ['gamma', ['alpha' => 3.0, 'scale' => 2.0], 6.0, 12.0],
    'gamma (shape below one)' => ['gamma', ['alpha' => 0.4, 'scale' => 1.0], 0.4, 0.4],
    'beta' => ['beta', ['alpha' => 2.0, 'beta' => 5.0], 2 / 7, 10 / (49 * 8)],
    // Variance omitted: it exists at α = 3, but the *sample* variance of a
    // Pareto only settles once the fourth moment does, and that needs α > 4.
    'pareto' => ['pareto', ['alpha' => 3.0, 'scale' => 1.0], 1.5, null],
]);

it('passes a chi-squared test against its own PMF', function (string $name, array $input): void {
    // Stronger than the moments above: two distributions can share a mean and a
    // variance and still be different shapes. χ² compares the whole histogram
    // against the analytic probability of every outcome.
    [$values] = drawFrom(new DistributionGenerator, ['count' => 4000, 'distribution' => $name, ...$input]);

    [$chi, $degrees] = chiSquaredAgainstPmf($values, Distributions::density($name, [
        'lambda' => 4.0, 'alpha' => 1.2, 'beta' => 5.0, 'scale' => 1.0,
        'trials' => 40, 'p' => 0.3, 'support' => 60, ...$input,
    ]));

    // The critical value at α = 0.001 is roughly d + 3.3√(2d) for these degrees
    // of freedom. Generous on purpose: the seed is fixed, so a pass is a fact
    // about the arithmetic, and the bound only has to catch a wrong shape.
    expect($chi)->toBeLessThan($degrees + 3.3 * sqrt(2 * $degrees));
})->with([
    'poisson' => ['poisson', ['lambda' => 4.0]],
    'poisson (PTRS)' => ['poisson', ['lambda' => 45.0]],
    'binomial' => ['binomial', ['trials' => 40, 'p' => 0.3]],
    'zipf' => ['zipf', ['alpha' => 1.2, 'support' => 60]],
]);

it('reports “undefined” rather than a number where the moment does not exist', function (): void {
    /*
     * The two distributions on the list that misbehave, and the reason they are
     * on it. A Cauchy has no mean — its sample mean wanders forever rather than
     * settling — and printing one rounded to three places would be the exact
     * false precision the whole module is built to disprove.
     */
    [, $cauchy] = drawFrom(new DistributionGenerator, ['distribution' => 'cauchy', 'count' => 2000]);
    [, $pareto] = drawFrom(new DistributionGenerator, ['distribution' => 'pareto', 'count' => 2000, 'alpha' => 1.5]);

    expect($cauchy['expected_mean'])->toBeNull()
        ->and($cauchy['expected_variance'])->toBeNull()
        ->and($cauchy['undefined_moments'])->toContain('no mean')
        // The mean over a tenth of the sample against the mean over all of it.
        // For anything well behaved these agree closely; for a Cauchy they are
        // routinely further apart than either number is from zero.
        ->and(abs($cauchy['mean_at_tenth'] - $cauchy['observed_mean']))->toBeGreaterThan(0.5)
        ->and($pareto['expected_mean'])->toEqualWithDelta(3.0, 0.001)
        ->and($pareto['expected_variance'])->toBeNull()
        ->and($pareto['undefined_moments'])->toContain('infinite');
});

it('emits a histogram whose expected counts are the density, integrated', function (string $name, array $input): void {
    /*
     * The overlay is the product, so it gets checked like one. `expected` must be
     * the analytic probability of each bin times the sample size — which means it
     * sums to the sample size, minus whatever the plot clipped.
     *
     * This caught a real error: the expected counts were originally the density
     * at each bin centre times the width, and for a Cauchy — whose bins are six
     * units wide around a peak one unit across — that under-counted the central
     * bar by more than half. On the one plot whose entire job is to show that the
     * sampler is right, the curve disagreed with the bars.
     */
    [, $meta] = drawFrom(new DistributionGenerator, ['count' => 3000, 'distribution' => $name, ...$input]);
    $histogram = $meta['histogram'];

    $inside = 3000 - $histogram['outside'];

    expect(array_sum($histogram['counts']))->toBe($inside)
        ->and(array_sum($histogram['expected']))->toBeGreaterThan($inside * 0.9)
        ->and(array_sum($histogram['expected']))->toBeLessThan(3000 * 1.02)
        ->and($histogram['counts'])->toHaveCount(count($histogram['expected']));
})->with([
    'poisson' => ['poisson', ['lambda' => 6.0]],
    'binomial' => ['binomial', ['trials' => 40, 'p' => 0.3]],
    'zipf' => ['zipf', ['alpha' => 1.1, 'support' => 40]],
    'gamma' => ['gamma', ['alpha' => 3.0, 'scale' => 2.0]],
    'beta' => ['beta', ['alpha' => 2.0, 'beta' => 5.0]],
    'exponential' => ['exponential', ['lambda' => 2.0]],
    'cauchy' => ['cauchy', ['scale' => 1.0]],
]);

it('keeps a Zipf draw looking like a power law rather than a uniform one', function (): void {
    // The failure this is here for: a Zipf sampler with the exponent dropped, or
    // with the cumulative table built on the wrong array, returns ranks in the
    // right range and is otherwise invisible. Rank 1 has to dominate.
    [$values] = drawFrom(new DistributionGenerator, ['distribution' => 'zipf', 'count' => 4000, 'alpha' => 1.2, 'support' => 100]);

    $counts = array_count_values($values);
    $top = $counts[1] ?? 0;

    expect($top / 4000)->toBeGreaterThan(0.2)
        // p(1)/p(2) = 2^s exactly, which for s = 1.2 is 2.30. Ten percent either
        // side covers the sampling noise on 4,000 draws.
        ->and($top / max(1, $counts[2] ?? 1))->toEqualWithDelta(2 ** 1.2, 0.35)
        ->and(max(array_keys($counts)))->toBeLessThanOrEqual(100);
});
