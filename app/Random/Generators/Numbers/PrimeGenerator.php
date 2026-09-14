<?php

declare(strict_types=1);

namespace App\Random\Generators\Numbers;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Probable primes at a chosen bit width — docs/04 §3.
 *
 * How every RSA key in the world starts: draw an odd number of the right size,
 * throw it away if a small prime divides it, and put what survives through
 * Miller–Rabin. There is no sieve big enough to *prove* a 256-bit number prime
 * cheaply, so the answer is probabilistic — and the probability is the point.
 * Each round of Miller–Rabin rejects a composite with probability at least ¾, so
 * forty independent rounds leave a false positive under 4^(−40) ≈ 10^(−24). That
 * is many orders of magnitude below the chance of the machine miscomputing the
 * test through a cosmic-ray bit flip, which is the honest comparison and is
 * printed next to the result.
 *
 * **Marked sensitive**, by the same argument as numbers.password and
 * numbers.bytes. A prime from a public, replayable seed is a prime an attacker
 * can regenerate, and primes are the one number type people paste directly into
 * key material. The trial division and the witnesses are all drawn from the
 * seeded stream, so the result stays deterministic — which is precisely why the
 * permalink has to be withheld rather than merely discouraged.
 *
 * bcmath rather than GMP: it ships in far more PHP builds, and bcpowmod is the
 * only operation here whose cost matters.
 */
final class PrimeGenerator extends BaseGenerator
{
    /**
     * The widest safe prime this will look for.
     *
     * Safe primes are rarer than ordinary ones by roughly another factor of
     * ln(p): at 128 bits that is one in a few thousand candidates, which lands
     * inside a second, and at 256 bits it is one in tens of thousands, which
     * measured at five seconds *per prime* with bcmath doing the modular
     * exponentiation in software. A control that quietly takes a minute is worse
     * than one that says no, so the width is capped when safe primes are asked
     * for and the cap is reported in the output.
     */
    private const SAFE_PRIME_MAX_BITS = 128;

    /**
     * Rounds of Miller–Rabin — docs/04 §3 says forty and forty is what this does.
     *
     * The number is conventional rather than derived: FIPS 186-4 allows far fewer
     * for large random candidates, because a *random* number surviving one round
     * is much more likely prime than the worst-case bound suggests. Forty costs
     * milliseconds at these sizes and needs no argument about which bound applies.
     */
    private const ROUNDS = 40;

    /**
     * Small primes for trial division, up to 251.
     *
     * Roughly 80% of odd candidates have a factor below 250, and each one costs a
     * single bcmod against forty modular exponentiations. Extending the list
     * further buys steeply less: the fraction of survivors falls like
     * Π(1 − 1/p), which is already flattening out by here.
     */
    private const SMALL_PRIMES = [
        3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, 73, 79,
        83, 89, 97, 101, 103, 107, 109, 113, 127, 131, 137, 139, 149, 151, 157, 163, 167,
        173, 179, 181, 191, 193, 197, 199, 211, 223, 227, 229, 233, 239, 241, 251,
    ];

    public function key(): string
    {
        return 'numbers.prime';
    }

    public function name(): string
    {
        return 'Primes';
    }

    public function tagline(): string
    {
        return 'Miller–Rabin, forty rounds, at the bit width you ask for.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function isSensitive(): bool
    {
        return true;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('bits', 'Bit width', default: 64, min: 8, max: 256, help: 'A 2048-bit RSA key is two 1024-bit primes. This stops at 256 because bcmath does the modular exponentiation in software and the wait stops being interactive.')
            ->int('count', 'How many', default: 5, min: 1, max: 20)
            ->bool('safe', 'Safe primes only', help: 'p where (p−1)/2 is also prime — what Diffie–Hellman wants. Rarer by another factor of ln(p), so the width is capped at 128 bits when this is on.')
            ->bool('twin', 'Prefer twins', help: 'Report whether p + 2 is also prime. Not a filter: twin primes thin out fast and searching for them at 64 bits would hang.')
            ->enum('format', 'Show as', [
                'decimal' => 'Decimal',
                'hex' => 'Hexadecimal',
                'both' => 'Decimal and hex',
            ], default: 'decimal');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('count');
        $safe = $params->bool('safe');
        $asked = $params->int('bits');
        $bits = $safe ? min($asked, self::SAFE_PRIME_MAX_BITS) : $asked;

        /*
         * A search budget, so a hostile-but-legal parameter set cannot hang the
         * request. Sized from the prime number theorem rather than picked: among
         * odd numbers near 2^b, one in ln(2^b)/2 is prime, so four times that per
         * prime asked for is generous enough that running out means something
         * genuinely unlucky happened. Running out returns what was found and says
         * so, rather than timing out with nothing.
         */
        $perPrime = log(2) * $bits / 2;
        $budget = $safe ? 15_000 : (int) min(20_000, 200 + 4 * $count * $perPrime);

        $primes = [];
        $candidates = 0;
        $trialRejected = 0;

        while (count($primes) < $count && $candidates < $budget) {
            $candidates++;
            $candidate = $this->candidate($rng, $bits);

            if ($this->hasSmallFactor($candidate)) {
                $trialRejected++;

                continue;
            }

            if (! $this->isProbablePrime($rng, $candidate, $bits)) {
                continue;
            }

            if ($safe && ! $this->isProbablePrime($rng, bcdiv(bcsub($candidate, '1'), '2'), $bits)) {
                continue;
            }

            $primes[] = [
                'decimal' => $candidate,
                'hex' => $this->toHex($candidate),
                'bits' => $bits,
                'safe' => $safe,
                'twin' => $params->bool('twin') && $this->isProbablePrime($rng, bcadd($candidate, '2'), $bits),
            ];
        }

        return new Result(
            value: $primes,
            display: $this->render($primes, $params->string('format', 'decimal')),
            meta: [
                'bits' => $bits,
                'found' => count($primes),
                'candidates_tested' => $candidates,
                'rejected_by_trial_division' => $trialRejected,
                // The prime number theorem, made visible. Among odd numbers near
                // 2^b, roughly one in ln(2^b)/2 is prime — so the count above
                // should sit near this, and watching it do so is the best part of
                // the generator.
                'expected_candidates_per_prime' => round(log(2) * $bits / 2, 1),
                'rounds' => self::ROUNDS,
                'false_positive_bound' => '4^-40, about 1 in 10^24 — far below the odds of the hardware getting the arithmetic wrong',
                'exhausted' => count($primes) < $count,
                'search_budget' => $budget,
                'width_capped' => $safe && $asked > self::SAFE_PRIME_MAX_BITS
                    ? sprintf('Safe primes are capped at %d bits: above that the search takes longer than a request should live.', self::SAFE_PRIME_MAX_BITS)
                    : null,
                'sensitive' => true,
                'note' => 'Probable primes, not proven ones: forty Miller–Rabin rounds with witnesses drawn from the same seeded stream as the candidates. There is no permalink, for the same reason there is none on the password generator — a prime anyone can regenerate from a link is not key material.',
            ],
        );
    }

    /**
     * A random odd integer with the top bit set.
     *
     * Both fixings matter. Without the top bit a "64-bit" prime is sometimes 61
     * bits, which silently weakens anything built on it; without the bottom bit
     * half of every candidate is even and half the search is wasted.
     */
    private function candidate(Rng $rng, int $bits): string
    {
        $bytes = intdiv($bits + 7, 8);
        $raw = $rng->bytes($bytes);

        $value = '0';
        foreach (str_split($raw) as $byte) {
            $value = bcadd(bcmul($value, '256'), (string) ord($byte));
        }

        // Keep the low bits−1 bits and set the top one, which lands the value in
        // [2^(bits−1), 2^bits) — exactly `bits` bits wide, by construction rather
        // than on average.
        $top = bcpow('2', (string) ($bits - 1));
        $value = bcadd(bcmod($value, $top), $top);

        return bcadd($value, bcmod($value, '2') === '0' ? '1' : '0');
    }

    private function hasSmallFactor(string $n): bool
    {
        foreach (self::SMALL_PRIMES as $prime) {
            $p = (string) $prime;

            if (bccomp($n, $p) === 0) {
                return false;
            }

            if (bcmod($n, $p) === '0') {
                return true;
            }
        }

        return false;
    }

    /**
     * Miller–Rabin.
     *
     * Write n − 1 = d·2^s with d odd. For a witness a, n is declared composite
     * unless a^d ≡ 1, or a^(d·2^r) ≡ −1 for some r < s. Every prime passes for
     * every witness; a composite passes for at most a quarter of them, which is
     * what makes forty independent rounds decisive.
     *
     * Witnesses come from the Rng, not from a fixed list. A fixed list would make
     * the test deterministic against a *known* set of pseudoprimes, and there are
     * composites constructed specifically to pass any published one.
     */
    private function isProbablePrime(Rng $rng, string $n, int $bits): bool
    {
        if (bccomp($n, '2') < 0) {
            return false;
        }

        if (bccomp($n, '3') <= 0) {
            return true;
        }

        if (bcmod($n, '2') === '0') {
            return false;
        }

        $d = bcsub($n, '1');
        $s = 0;

        while (bcmod($d, '2') === '0') {
            $d = bcdiv($d, '2');
            $s++;
        }

        $nMinusOne = bcsub($n, '1');

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $a = $this->witness($rng, $n, $bits);
            $x = bcpowmod($a, $d, $n);

            if ($x === '1' || bccomp($x, $nMinusOne) === 0) {
                continue;
            }

            $passed = false;

            for ($r = 1; $r < $s; $r++) {
                $x = bcpowmod($x, '2', $n);

                if (bccomp($x, $nMinusOne) === 0) {
                    $passed = true;

                    break;
                }
            }

            if (! $passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * A witness in [2, n − 2], drawn from the stream.
     *
     * Built from raw bytes rather than from Rng::intBetween, which works in
     * machine integers — and a 256-bit modulus is not a machine integer. The
     * first version of this asked intBetween for a 63-bit range, where its
     * internal `1 << bits` overflows to a negative mask and the "witness" comes
     * back negative. Miller–Rabin then declared genuine primes composite: the
     * generator still returned primes, just three times fewer than the prime
     * number theorem says it should have, which is exactly the kind of quiet
     * wrongness that never throws. The density check in
     * tests/Unit/NumbersGeneratorsTest.php is there because of it.
     *
     * Eight bytes wider than the modulus, so the modulo bias is one part in 2^64
     * rather than one part in the range — and unpredictability is all that is
     * asked of a witness anyway.
     */
    private function witness(Rng $rng, string $n, int $bits): string
    {
        $span = bcsub($n, '3');

        if (bccomp($span, '1') < 0) {
            return '2';
        }

        $value = '0';
        foreach (str_split($rng->bytes(intdiv($bits + 7, 8) + 8)) as $byte) {
            $value = bcadd(bcmul($value, '256'), (string) ord($byte));
        }

        return bcadd('2', bcmod($value, bcadd($span, '1')));
    }

    private function toHex(string $decimal): string
    {
        $hex = '';
        $n = $decimal;

        while (bccomp($n, '0') > 0) {
            $hex = dechex((int) bcmod($n, '16')).$hex;
            $n = bcdiv($n, '16');
        }

        return '0x'.($hex === '' ? '0' : $hex);
    }

    private function render(array $primes, string $format): string
    {
        if ($primes === []) {
            return 'No prime found inside the search budget — try fewer bits, or switch off safe primes.';
        }

        return implode(PHP_EOL, array_map(fn (array $p): string => match ($format) {
            'hex' => $p['hex'],
            'both' => $p['decimal'].'  '.$p['hex'],
            default => $p['decimal'],
        }, $primes));
    }
}
