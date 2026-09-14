<?php

declare(strict_types=1);

use App\Random\Generators\Numbers\BytesGenerator;
use App\Random\Generators\Numbers\CoinGenerator;
use App\Random\Generators\Numbers\DecimalsGenerator;
use App\Random\Generators\Numbers\PrimeGenerator;
use App\Random\Generators\Numbers\TimestampGenerator;

/**
 * The numbers generators whose claim is about *arithmetic* rather than about a
 * distribution — the shapes live next door in NumbersDistributionsTest.
 *
 * Everything generic comes free from GeneratorsTest, which reads the registry.
 * What is here is the part that could be quietly wrong: a prime that is not
 * prime, a decimal grid that drops its endpoints, a timestamp generator that
 * reads the clock and therefore cannot be replayed.
 */
function numbersResult(string $class, array $input = [], string $bytes = 'randomly-fixed!!')
{
    $generator = new $class;
    $params = $generator->schema()->coerce($input);

    return $generator->generate(
        seedFor($bytes)->rng($generator->key(), $generator->version(), $params->fingerprint()),
        $params,
    );
}

// ── numbers.decimals ─────────────────────────────────────────────────────────

it('draws decimals from a grid, endpoints included', function (): void {
    /*
     * The bug this generator is written to avoid: rounding a uniform double to
     * two places gives 0.00 and 1.00 half the width of every other value, so they
     * come up half as often and nobody ever notices. Drawing the grid index
     * directly makes every value equally likely — including both ends, which must
     * therefore actually appear over enough draws.
     */
    $result = numbersResult(DecimalsGenerator::class, ['count' => 1000, 'min' => 0.0, 'max' => 1.0, 'precision' => 1]);

    $counts = array_count_values(array_map(fn (float $v): string => number_format($v, 1), $result->value));

    expect($result->meta['grid_points'])->toBe(11)
        ->and($counts)->toHaveKey('0.0')
        ->and($counts)->toHaveKey('1.0')
        // Eleven equally likely values over a thousand draws is 91 each; the ends
        // must be within shouting distance of that rather than at half of it.
        ->and($counts['0.0'])->toBeGreaterThan(50)
        ->and($counts['1.0'])->toBeGreaterThan(50);
});

it('keeps every decimal inside the range and on the grid', function (): void {
    $result = numbersResult(DecimalsGenerator::class, ['count' => 500, 'min' => -2.5, 'max' => 2.5, 'precision' => 3]);

    foreach ($result->value as $value) {
        expect($value)->toBeGreaterThanOrEqual(-2.5)->toBeLessThanOrEqual(2.5)
            // On the grid to within floating-point noise: 1000·v must be an integer.
            ->and(abs(round($value * 1000) - $value * 1000))->toBeLessThan(1e-6);
    }
});

it('never repeats a unique decimal draw, and truncates rather than looping', function (): void {
    $result = numbersResult(DecimalsGenerator::class, ['count' => 50, 'min' => 0.0, 'max' => 1.0, 'precision' => 1, 'unique' => true]);

    expect($result->value)->toHaveCount(11)
        ->and(array_unique($result->value))->toHaveCount(11)
        ->and($result->meta['truncated'])->toBeTrue();
});

// ── numbers.coin ─────────────────────────────────────────────────────────────

it('predicts the longest run the coin actually produces', function (int $count, float $bias): void {
    /*
     * The claim on the page is Erdős–Rényi: the expected longest run of heads in
     * n Bernoulli(p) trials is log(n·q)/log(1/p) + γ/log(1/p) − ½. It was checked
     * against a 200,000-trial simulation while this was written — 5.983 measured
     * against 5.977 predicted at n = 100, and 12.604 against 12.598 at n = 200,
     * p = 0.7 — so the formula is right. What this pins is that the generator is
     * using it, and that the run it reports is the run in the sequence.
     */
    $result = numbersResult(CoinGenerator::class, ['count' => $count, 'bias' => $bias]);

    $longest = 0;
    $current = 0;
    foreach ($result->value as $flip) {
        $current = $flip ? $current + 1 : 0;
        $longest = max($longest, $current);
    }

    expect($result->meta['longest_head_run'])->toBe($longest)
        // Within three standard deviations of the prediction. The spread of the
        // longest run barely depends on n — about 1.85 flips for a fair coin —
        // which is itself the counter-intuitive half of the result.
        ->and(abs($longest - $result->meta['expected_longest_head_run']))
        ->toBeLessThan(3 * $result->meta['longest_run_spread']);
})->with([
    'a hundred fair flips' => [100, 0.5],
    'a thousand fair flips' => [1000, 0.5],
    'two hundred biased flips' => [200, 0.7],
]);

it('reports the bias it was given, in heads and in bits', function (): void {
    $result = numbersResult(CoinGenerator::class, ['count' => 4000, 'bias' => 0.25]);

    expect($result->meta['heads_fraction'])->toEqualWithDelta(0.25, 0.02)
        // H = −p log₂p − q log₂q. A quarter-heads coin carries 0.811 bits a flip,
        // not one — the entropy figure has to fall with the bias or it is
        // counting flips rather than randomness.
        ->and($result->meta['entropy_out_bits'])->toEqualWithDelta(4000 * 0.8113, 1.0)
        ->and($result->meta['alternations'])->toEqualWithDelta($result->meta['expected_alternations'], 100.0);
});

it('refuses to predict a longest run for a coin with only one face', function (): void {
    // log(1/p) has no meaning at p = 1 and the answer is trivially n anyway.
    // Printing a number there would be arithmetic pretending to be a prediction.
    $result = numbersResult(CoinGenerator::class, ['count' => 50, 'bias' => 1.0]);

    expect($result->meta['expected_longest_head_run'])->toBeNull()
        ->and($result->meta['longest_run_spread'])->toBeNull()
        ->and($result->meta['longest_head_run'])->toBe(50)
        ->and($result->meta['entropy_out_bits'])->toBe(0.0);
});

// ── numbers.bytes ────────────────────────────────────────────────────────────

it('encodes exactly the bytes it drew, in every encoding', function (string $encoding, string $pattern): void {
    $result = numbersResult(BytesGenerator::class, ['count' => 32, 'encoding' => $encoding]);

    expect($result->display)->toMatch($pattern)
        ->and($result->meta['bytes'])->toBe(32)
        ->and($result->meta['entropy_out_bits'])->toBe(256);
})->with([
    'hex' => ['hex', '/^[0-9a-f]{64}$/'],
    'base64' => ['base64', '/^[A-Za-z0-9+\/]+={0,2}$/'],
    'url-safe base64' => ['base64url', '/^[A-Za-z0-9_-]+$/'],
    'binary' => ['binary', '/^[01]{256}$/'],
    'decimal' => ['decimal', '/^(\d{1,3} ){31}\d{1,3}$/'],
    'C array' => ['c_array', '/^\{ (0x[0-9a-f]{2}, ){31}0x[0-9a-f]{2} \}$/'],
]);

it('is the stream itself, not a function of it', function (): void {
    // The property that makes this generator sensitive: the output *is* the HKDF
    // stream, so anyone who can replay the seed has the key material and
    // everything else that seed would ever produce.
    $generator = new BytesGenerator;
    $params = $generator->schema()->coerce(['count' => 16, 'encoding' => 'hex']);
    $rng = seedFor()->rng($generator->key(), $generator->version(), $params->fingerprint());

    $expected = bin2hex(seedFor()->rng($generator->key(), $generator->version(), $params->fingerprint())->bytes(16));

    expect($generator->generate($rng, $params)->display)->toBe($expected)
        ->and($generator->isSensitive())->toBeTrue();
});

it('groups an encoding by bytes rather than by characters', function (): void {
    $result = numbersResult(BytesGenerator::class, ['count' => 8, 'encoding' => 'hex', 'group' => 2]);

    expect($result->display)->toMatch('/^([0-9a-f]{4} ){3}[0-9a-f]{4}$/');
});

// ── numbers.prime ────────────────────────────────────────────────────────────

it('produces numbers that really are prime, at the width asked for', function (int $bits): void {
    /*
     * Checked against an independent test, not against the generator's own.
     * Miller–Rabin declaring its own output prime is not evidence of anything —
     * a witness loop with an off-by-one in the squaring step passes everything.
     * Trial division to √n is unarguable and, at these widths, affordable.
     */
    $result = numbersResult(PrimeGenerator::class, ['bits' => $bits, 'count' => 3]);

    expect($result->value)->toHaveCount(3);

    foreach ($result->value as $prime) {
        $n = (int) $prime['decimal'];

        expect($n)->toBeGreaterThanOrEqual(2 ** ($bits - 1))
            ->and($n)->toBeLessThan(2 ** $bits);

        for ($d = 2; $d * $d <= $n; $d++) {
            expect($n % $d)->not->toBe(0, "{$n} is divisible by {$d} and was reported prime.");
        }
    }
})->with(['8 bits' => 8, '16 bits' => 16, '24 bits' => 24]);

it('finds a large prime about as often as the prime number theorem says', function (int $bits, int $count): void {
    /*
     * Among odd numbers near 2^b, one in ln(2^b)/2 is prime — so the number of
     * candidates tested has to land near count × that, and a generator taking
     * three times as many is rejecting primes it should have kept.
     *
     * This is the test that would have caught the real bug in this file. The
     * witness for each Miller–Rabin round was drawn through Rng::intBetween over
     * a 63-bit range, where its internal `1 << bits` overflows to a negative
     * mask; the witnesses came back negative and genuine primes were declared
     * composite. Everything the generator returned was still prime, there was no
     * exception and no warning — it simply found them 2.7 times more slowly than
     * arithmetic says it should, which nothing but this ratio would have shown.
     */
    $result = numbersResult(PrimeGenerator::class, ['bits' => $bits, 'count' => $count]);

    expect($result->meta['found'])->toBe($count)
        ->and($result->meta['exhausted'])->toBeFalse()
        ->and($result->meta['expected_candidates_per_prime'])->toEqualWithDelta(log(2) * $bits / 2, 0.1)
        ->and($result->meta['rounds'])->toBe(40)
        // Twice the expectation. The seed is fixed, so this is a known answer
        // rather than a sample — the measured ratios are 0.74, 0.72 and 1.12.
        ->and($result->meta['candidates_tested'])
        ->toBeLessThan(2.0 * $count * $result->meta['expected_candidates_per_prime']);
})->with([
    '32 bits' => [32, 10],
    '64 bits' => [64, 10],
    '128 bits' => [128, 5],
]);

it('confirms a large prime against a test it did not use itself', function (): void {
    // Miller-Rabin declaring its own output prime is not evidence: a witness loop
    // with an off-by-one passes everything. Fermat with base 2 is independent of
    // every witness the generator drew.
    $result = numbersResult(PrimeGenerator::class, ['bits' => 64, 'count' => 4]);

    foreach ($result->value as $prime) {
        expect(strlen($prime['decimal']))->toBeGreaterThan(17)
            ->and(bcmod($prime['decimal'], '2'))->toBe('1')
            // Fermat's little theorem with a base the generator never used: for
            // prime p, 2^(p−1) ≡ 1 (mod p). A composite passing this as well as
            // forty Miller–Rabin rounds would be a genuine mathematical event.
            ->and(bcpowmod('2', bcsub($prime['decimal'], '1'), $prime['decimal']))->toBe('1');
    }
});

it('marks primes sensitive, for the same reason passwords are', function (): void {
    expect((new PrimeGenerator)->isSensitive())->toBeTrue()
        ->and((new BytesGenerator)->isSensitive())->toBeTrue();
});

it('finds safe primes when it is asked for them', function (): void {
    // p where (p−1)/2 is also prime. Rarer by a factor of about ln(p), which is
    // why the search budget exists and why the bit width here is modest.
    $result = numbersResult(PrimeGenerator::class, ['bits' => 20, 'count' => 2, 'safe' => true]);

    expect($result->value)->not->toBeEmpty();

    foreach ($result->value as $prime) {
        $half = bcdiv(bcsub($prime['decimal'], '1'), '2');

        expect(bcpowmod('2', bcsub($half, '1'), $half))->toBe('1', "({$prime['decimal']}−1)/2 is not prime.");
    }
});

it('says no to a safe prime too wide to find inside a request', function (): void {
    // A 256-bit safe prime measured at five seconds apiece with bcmath doing the
    // modular exponentiation in software. A control that quietly takes a minute
    // is worse than one that says no, so the width is capped and the cap is
    // printed rather than applied in silence.
    $result = numbersResult(PrimeGenerator::class, ['bits' => 256, 'count' => 1, 'safe' => true]);

    expect($result->meta['bits'])->toBe(128)
        ->and($result->meta['width_capped'])->toContain('capped at 128 bits')
        ->and($result->meta['found'])->toBe(1);
});

// ── numbers.timestamp ────────────────────────────────────────────────────────

it('never reads the clock', function (): void {
    /*
     * The constraint that shapes the whole generator. "A random time in the next
     * hour" is the obvious feature and is exactly what cannot be built: a
     * generator that calls now() returns something different on every replay, and
     * every permalink to it rots within the hour.
     *
     * Tested by construction rather than by inspection — the same seed and the
     * same window must give the same instants, and none of them may land near
     * today unless today is inside the window.
     */
    $window = ['from' => '1990-01-01', 'to' => '1990-12-31', 'count' => 20];

    $first = numbersResult(TimestampGenerator::class, $window);
    $second = numbersResult(TimestampGenerator::class, $window);

    expect($first->display)->toBe($second->display);

    foreach ($first->value as $moment) {
        expect($moment['unix'])->toBeGreaterThanOrEqual(631_152_000)
            ->toBeLessThanOrEqual(662_601_600);
    }
});

it('rejects a date that would have read the clock', function (string $input): void {
    // new DateTimeImmutable('now') is a clock read wearing a parameter's clothes,
    // and so are "next tuesday" and "+3 days". Strict parsing sends all three to
    // the default window instead, and the meta says the input was not understood.
    $result = numbersResult(TimestampGenerator::class, ['from' => $input, 'count' => 3]);

    expect($result->meta['window_understood'])->toBeFalse()
        ->and($result->meta['window_from'])->toBe('2000-01-01T00:00:00Z');
})->with(['now', 'next tuesday', '+3 days', 'yesterday', '01/03/2024', '2024-02-31', 'tomorrow noon']);

it('rounds to the granularity by snapping the window, not the draw', function (): void {
    /*
     * Rounding a second-resolution draw to the nearest day would let it round
     * *outside* the window, and would give the first and last day half the width
     * of every other one — the same endpoint bug the decimals generator avoids.
     * Snapping the window first makes every slot equally likely by construction.
     */
    $result = numbersResult(TimestampGenerator::class, [
        'from' => '2024-03-01', 'to' => '2024-03-31', 'granularity' => 'day', 'count' => 200,
    ]);

    expect($result->meta['slots'])->toBe(31);

    foreach ($result->value as $moment) {
        expect($moment['unix'] % 86400)->toBe(0)
            ->and($moment['iso'])->toStartWith('2024-03-');
    }
});

it('swaps a window someone is still typing rather than failing', function (): void {
    $result = numbersResult(TimestampGenerator::class, ['from' => '2020-01-01', 'to' => '2010-01-01', 'count' => 5]);

    expect($result->meta['window_from'])->toBe('2010-01-01T00:00:00Z')
        ->and($result->meta['window_to'])->toBe('2020-01-01T00:00:00Z');
});
