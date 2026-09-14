<?php

declare(strict_types=1);

namespace App\Random\Numbers;

use App\Random\Rng\Rng;

/**
 * The distribution library behind numbers.distribution — docs/04 §2.
 *
 * Every entry here knows three things about itself: how to draw from it, what its
 * density is, and what its first two moments are. That triple is the whole reason
 * the module is worth building. A sampler on its own cannot be checked — a broken
 * Poisson returns perfectly plausible small integers — but a sampler next to its
 * own analytic density can: draw ten thousand values, bin them, and compare the
 * bars against the curve. That comparison is what the studio plots and what
 * tests/Unit/NumbersDistributionsTest.php asserts.
 *
 * Setup that does not depend on the draw is hoisted into `sampler()`, which
 * returns a closure. Zipf needs a cumulative table over its ranks and Gamma needs
 * two constants derived from its shape; building either per draw would be the
 * difference between one multiplication and a thousand.
 */
final class Distributions
{
    /**
     * The catalogue, in the order the panel lists them.
     *
     * `discrete` decides how the histogram is built — one bin per integer outcome
     * rather than a range sliced into bars — and `uses` is what lets the panel
     * say which of the shared controls each distribution actually reads. Ten
     * controls serving eight distributions is only tolerable if the ones being
     * ignored are named out loud.
     */
    public const SPECS = [
        'poisson' => [
            'label' => 'Poisson — arrivals in a fixed window',
            'discrete' => true,
            'uses' => ['lambda'],
            'note' => 'Counts of events that happen independently at a constant average rate: emails in an hour, decays in a second. Its variance equals its mean, which is the property that makes it recognisable.',
        ],
        'binomial' => [
            'label' => 'Binomial — successes out of n tries',
            'discrete' => true,
            'uses' => ['trials', 'p'],
            'note' => 'n independent tries, each succeeding with probability p. Everything from coin flips to A/B tests lives here.',
        ],
        'zipf' => [
            'label' => 'Zipf — rank frequency, the long tail',
            'discrete' => true,
            'uses' => ['alpha', 'support'],
            'note' => 'p(k) ∝ k^(−s). The rank-frequency law of word usage, city sizes and file requests: the second item is half as common as the first, the third a third as common, and the tail never quite ends.',
        ],
        'exponential' => [
            'label' => 'Exponential — waiting time',
            'discrete' => false,
            'uses' => ['lambda'],
            'note' => 'The gap between Poisson arrivals. Memoryless: having waited ten minutes tells you nothing about how much longer you will wait, which is the least intuitive true fact in elementary probability.',
        ],
        'gamma' => [
            'label' => 'Gamma — the sum of k waits',
            'discrete' => false,
            'uses' => ['alpha', 'scale'],
            'note' => 'How long until the k-th arrival. Shape 1 is the exponential; large shapes approach a normal, which is the central limit theorem happening in front of you.',
        ],
        'beta' => [
            'label' => 'Beta — a proportion between 0 and 1',
            'discrete' => false,
            'uses' => ['alpha', 'beta'],
            'note' => 'Bounded to [0, 1], so it is the natural shape for a rate or a probability. α = β = 1 is uniform; both large is a bell pinned to the unit interval.',
        ],
        'pareto' => [
            'label' => 'Pareto — the 80/20 power law',
            'discrete' => false,
            'uses' => ['alpha', 'scale'],
            'note' => 'Wealth, city populations, file sizes. Below α = 2 the variance is infinite and below α = 1 so is the mean — the sample statistics simply do not settle, however many draws you take.',
        ],
        'cauchy' => [
            'label' => 'Cauchy — no mean, no variance',
            'discrete' => false,
            'uses' => ['scale'],
            'note' => 'The ratio of two normals, and the standard counterexample: its tails are so fat that the sample mean of a million draws is no better an estimate than a single draw. Watch the running mean refuse to converge.',
        ],
    ];

    /** @return array<string, string> value => label, for a generator's enum schema */
    public static function labels(): array
    {
        return array_map(fn (array $spec): string => $spec['label'], self::SPECS);
    }

    public static function isDiscrete(string $name): bool
    {
        return self::SPECS[$name]['discrete'] ?? false;
    }

    /**
     * A draw function for one distribution, with its setup already done.
     *
     * @param  array<string, float|int>  $p
     * @return \Closure(Rng): float
     */
    public static function sampler(string $name, array $p): \Closure
    {
        return match ($name) {
            'binomial' => self::binomialSampler((int) $p['trials'], (float) $p['p']),
            'zipf' => self::zipfSampler((float) $p['alpha'], (int) $p['support']),
            'exponential' => fn (Rng $rng): float => $rng->exponential((float) $p['lambda']),
            'gamma' => fn (Rng $rng): float => self::gamma($rng, (float) $p['alpha']) * (float) $p['scale'],
            'beta' => function (Rng $rng) use ($p): float {
                // Two Gammas of the right shapes, normalised by their own sum. The
                // ratio is Beta(α, β) exactly — no rejection, no approximation —
                // and it is why Marsaglia–Tsang is the only sampler this file
                // needs for three of the eight entries.
                $a = self::gamma($rng, (float) $p['alpha']);
                $b = self::gamma($rng, (float) $p['beta']);

                return $a + $b > 0 ? $a / ($a + $b) : 0.5;
            },
            // Inverse CDF. U is taken from (0, 1] rather than [0, 1), because
            // U = 0 divides by zero and would return infinity on one draw in 2^53.
            'pareto' => fn (Rng $rng): float => (float) $p['scale'] / ((1.0 - $rng->float()) ** (1.0 / (float) $p['alpha'])),
            'cauchy' => fn (Rng $rng): float => (float) $p['scale'] * tan(M_PI * ($rng->float() - 0.5)),
            default => fn (Rng $rng): float => (float) self::poisson($rng, (float) $p['lambda']),
        };
    }

    /**
     * The density this sampler is supposed to be following — a PDF for the
     * continuous entries, a PMF for the discrete ones.
     *
     * Emitted alongside the histogram so the studio can overlay the two. The
     * overlay is the point: bars that sit proud of the curve are sampling noise
     * and shrink as the count rises, while bars with the wrong *shape* are a bug
     * and do not.
     *
     * @param  array<string, float|int>  $p
     * @return \Closure(float): float
     */
    public static function density(string $name, array $p): \Closure
    {
        return match ($name) {
            'binomial' => function (float $k) use ($p): float {
                $n = (int) $p['trials'];
                $prob = (float) $p['p'];

                if ($k < 0 || $k > $n || $k !== floor($k)) {
                    return 0.0;
                }

                // In log space throughout: 500 choose 250 is about 10^149, which
                // overflows a double long before the tiny p^k that would have
                // cancelled it back down.
                return exp(
                    self::logGamma($n + 1) - self::logGamma($k + 1) - self::logGamma($n - $k + 1)
                    + $k * log($prob) + ($n - $k) * log(1 - $prob)
                );
            },
            'zipf' => function (float $k) use ($p): float {
                $s = (float) $p['alpha'];
                $n = (int) $p['support'];

                if ($k < 1 || $k > $n || $k !== floor($k)) {
                    return 0.0;
                }

                return ($k ** -$s) / self::harmonic($n, $s);
            },
            'exponential' => function (float $x) use ($p): float {
                $lambda = (float) $p['lambda'];

                return $x < 0 ? 0.0 : $lambda * exp(-$lambda * $x);
            },
            'gamma' => function (float $x) use ($p): float {
                $k = (float) $p['alpha'];
                $theta = (float) $p['scale'];

                if ($x <= 0) {
                    return 0.0;
                }

                return exp(($k - 1) * log($x) - $x / $theta - self::logGamma($k) - $k * log($theta));
            },
            'beta' => function (float $x) use ($p): float {
                $a = (float) $p['alpha'];
                $b = (float) $p['beta'];

                if ($x <= 0 || $x >= 1) {
                    return 0.0;
                }

                $logB = self::logGamma($a) + self::logGamma($b) - self::logGamma($a + $b);

                return exp(($a - 1) * log($x) + ($b - 1) * log(1 - $x) - $logB);
            },
            'pareto' => function (float $x) use ($p): float {
                $a = (float) $p['alpha'];
                $xm = (float) $p['scale'];

                return $x < $xm ? 0.0 : $a * ($xm ** $a) / ($x ** ($a + 1));
            },
            'cauchy' => function (float $x) use ($p): float {
                $g = (float) $p['scale'];

                return 1.0 / (M_PI * $g * (1 + ($x / $g) ** 2));
            },
            default => function (float $k) use ($p): float {
                $lambda = (float) $p['lambda'];

                if ($k < 0 || $k !== floor($k)) {
                    return 0.0;
                }

                return exp(-$lambda + $k * log($lambda) - self::logGamma($k + 1));
            },
        };
    }

    /**
     * The analytic mean and variance, or null where they do not exist.
     *
     * Null is the interesting case and the reason this returns nullable floats
     * rather than numbers. A Cauchy has no mean; a Pareto with α ≤ 2 has no
     * variance. Printing a sample mean next to "undefined" is the clearest way to
     * show that the number on the left is not an estimate of anything.
     *
     * @param  array<string, float|int>  $p
     * @return array{mean: ?float, variance: ?float}
     */
    public static function moments(string $name, array $p): array
    {
        return match ($name) {
            'binomial' => [
                'mean' => (int) $p['trials'] * (float) $p['p'],
                'variance' => (int) $p['trials'] * (float) $p['p'] * (1 - (float) $p['p']),
            ],
            'zipf' => self::zipfMoments((float) $p['alpha'], (int) $p['support']),
            'exponential' => [
                'mean' => 1 / (float) $p['lambda'],
                'variance' => 1 / ((float) $p['lambda'] ** 2),
            ],
            'gamma' => [
                'mean' => (float) $p['alpha'] * (float) $p['scale'],
                'variance' => (float) $p['alpha'] * ((float) $p['scale'] ** 2),
            ],
            'beta' => self::betaMoments((float) $p['alpha'], (float) $p['beta']),
            'pareto' => self::paretoMoments((float) $p['alpha'], (float) $p['scale']),
            'cauchy' => ['mean' => null, 'variance' => null],
            default => ['mean' => (float) $p['lambda'], 'variance' => (float) $p['lambda']],
        };
    }

    /**
     * Poisson — Knuth below λ = 30, PTRS above it.
     *
     * Knuth's method multiplies uniforms until the product drops under e^(−λ),
     * which takes λ + 1 draws on average: charming, exact, and linear in λ. At
     * λ = 500 that is five hundred uniforms per value and e^(−500) has already
     * underflowed to zero, so the loop never terminates. Hörmann's PTRS is
     * constant-time rejection and takes over before either problem bites.
     */
    public static function poisson(Rng $rng, float $lambda): int
    {
        if ($lambda <= 0) {
            return 0;
        }

        if ($lambda < 30) {
            $limit = exp(-$lambda);
            $product = 1.0;
            $k = 0;

            do {
                $k++;
                $product *= $rng->float();
            } while ($product > $limit);

            return $k - 1;
        }

        return self::poissonPtrs($rng, $lambda);
    }

    /**
     * Marsaglia–Tsang Gamma, shape only — multiply by θ at the call site.
     *
     * The squeeze accepts on the first try about 98% of the time, and the whole
     * method is one normal and one uniform per attempt. The α < 1 case is handled
     * by the standard boost rather than by a second algorithm: if G ~ Gamma(α+1)
     * then G·U^(1/α) ~ Gamma(α), which keeps the sampler to one branch.
     */
    public static function gamma(Rng $rng, float $shape): float
    {
        if ($shape <= 0) {
            return 0.0;
        }

        if ($shape < 1) {
            return self::gamma($rng, $shape + 1) * ((1.0 - $rng->float()) ** (1.0 / $shape));
        }

        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9 * $d);

        while (true) {
            $x = $rng->gaussian();
            $v = (1 + $c * $x) ** 3;

            // v ≤ 0 means the normal landed below −1/c, where the cube transform
            // leaves the positive half-line. Redraw rather than clamp: clamping
            // would pile mass onto zero.
            if ($v <= 0) {
                continue;
            }

            $u = $rng->float();

            // The cheap squeeze first: a polynomial that brackets the log test
            // from below, so most draws never touch a logarithm at all.
            if ($u < 1 - 0.0331 * ($x ** 4)) {
                return $d * $v;
            }

            if (log($u) < 0.5 * $x * $x + $d * (1 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    /**
     * Binomial by inversion (BINV), with the p > ½ reflection.
     *
     * The recurrence walks the PMF upward from k = 0 accumulating probability
     * until it passes a single uniform, which costs about np steps. Reflecting
     * p > ½ onto 1 − p halves the worst case, and `trials` is capped by the
     * schema at 500 for the same reason: past that the right method is Hörmann's
     * BTRS, and a subtly wrong BTRS is exactly the kind of silent bug this module
     * exists to be able to detect rather than to ship.
     */
    private static function binomialSampler(int $n, float $p): \Closure
    {
        $flip = $p > 0.5;
        $q = $flip ? 1 - $p : $p;
        $ratio = $q / (1 - $q);
        $first = (1 - $q) ** $n;

        return function (Rng $rng) use ($n, $flip, $ratio, $first): float {
            $u = $rng->float();
            $term = $first;
            $cumulative = $term;
            $k = 0;

            while ($u > $cumulative && $k < $n) {
                $k++;
                $term *= $ratio * ($n - $k + 1) / $k;
                $cumulative += $term;
            }

            return (float) ($flip ? $n - $k : $k);
        };
    }

    /**
     * Zipf by inverse CDF over a precomputed table.
     *
     * docs/04 §2 suggests rejection on the continuous approximation, which is the
     * right method for unbounded support. This one is bounded — the panel asks
     * for N ranks — and with a bounded support the exact cumulative table is both
     * simpler and *exactly* right, which matters because the χ² test in the suite
     * compares the draws against this same PMF. An approximation would make that
     * test a test of the approximation.
     *
     * Binary search rather than a linear scan: at N = 2000 the tail ranks would
     * otherwise cost two thousand comparisons each, and the tail is where the
     * interesting draws are.
     */
    private static function zipfSampler(float $s, int $n): \Closure
    {
        $cumulative = [];
        $total = 0.0;

        for ($k = 1; $k <= $n; $k++) {
            $total += $k ** -$s;
            $cumulative[] = $total;
        }

        return function (Rng $rng) use ($cumulative, $total, $n): float {
            $target = $rng->float() * $total;

            $lo = 0;
            $hi = $n - 1;

            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);

                if ($cumulative[$mid] < $target) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            return (float) ($lo + 1);
        };
    }

    /** Hörmann's PTRS: transformed rejection, constant time in λ. */
    private static function poissonPtrs(Rng $rng, float $lambda): int
    {
        $b = 0.931 + 2.53 * sqrt($lambda);
        $a = -0.059 + 0.02483 * $b;
        $inverseAlpha = 1.1239 + 1.1328 / ($b - 3.4);
        $vr = 0.9277 - 3.6224 / ($b - 2);

        while (true) {
            $u = $rng->float() - 0.5;
            $v = $rng->float();
            $us = 0.5 - abs($u);

            $k = (int) floor((2 * $a / $us + $b) * $u + $lambda + 0.43);

            // The fast acceptance region, which takes about 99% of draws without
            // evaluating a single logarithm.
            if ($us >= 0.07 && $v <= $vr) {
                return $k;
            }

            if ($k < 0 || ($us < 0.013 && $v > $us)) {
                continue;
            }

            if (log($v * $inverseAlpha / ($a / ($us * $us) + $b)) <= -$lambda + $k * log($lambda) - self::logGamma($k + 1)) {
                return $k;
            }
        }
    }

    /** @return array{mean: ?float, variance: ?float} */
    private static function zipfMoments(float $s, int $n): array
    {
        $h = self::harmonic($n, $s);
        $mean = self::harmonic($n, $s - 1) / $h;

        return [
            'mean' => $mean,
            'variance' => self::harmonic($n, $s - 2) / $h - $mean ** 2,
        ];
    }

    /** @return array{mean: ?float, variance: ?float} */
    private static function betaMoments(float $a, float $b): array
    {
        $sum = $a + $b;

        return [
            'mean' => $a / $sum,
            'variance' => ($a * $b) / ($sum * $sum * ($sum + 1)),
        ];
    }

    /** @return array{mean: ?float, variance: ?float} */
    private static function paretoMoments(float $a, float $xm): array
    {
        return [
            // Both of these diverge, and the thresholds are the whole story of the
            // distribution: α ≤ 1 and the average of your sample grows without
            // bound as you draw more of it.
            'mean' => $a > 1 ? $a * $xm / ($a - 1) : null,
            'variance' => $a > 2 ? ($xm ** 2 * $a) / ((($a - 1) ** 2) * ($a - 2)) : null,
        ];
    }

    /** Generalised harmonic number H(n, s) = Σ k^(−s), the Zipf normaliser. */
    private static function harmonic(int $n, float $s): float
    {
        $sum = 0.0;

        for ($k = 1; $k <= $n; $k++) {
            $sum += $k ** -$s;
        }

        return $sum;
    }

    /**
     * log Γ(x) by the Lanczos approximation, g = 7, n = 9.
     *
     * PHP has no gamma function, and every discrete density here needs one: a
     * factorial written as a product overflows at 171 and this module hands out
     * binomials with n = 500. Accurate to about 15 significant figures across the
     * range anything here evaluates, which is the double's own precision.
     */
    public static function logGamma(float $x): float
    {
        static $coefficients = [
            0.99999999999980993,
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.9843695780195716e-6,
            1.5056327351493116e-7,
        ];

        // Reflection for x < ½: the series is only valid to the right, and
        // Γ(x)Γ(1−x) = π/sin(πx) moves the argument there.
        if ($x < 0.5) {
            return log(M_PI / abs(sin(M_PI * $x))) - self::logGamma(1 - $x);
        }

        $x -= 1;
        $a = $coefficients[0];
        $t = $x + 7.5;

        for ($i = 1; $i < 9; $i++) {
            $a += $coefficients[$i] / ($x + $i);
        }

        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
    }
}
