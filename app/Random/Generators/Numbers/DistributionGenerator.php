<?php

declare(strict_types=1);

namespace App\Random\Generators\Numbers;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Numbers\Distributions;
use App\Random\Rng\Rng;

/**
 * The distribution lab — docs/04 §2.
 *
 * numbers.gaussian does one distribution properly and plots it. This does eight,
 * and the reason it is a separate generator rather than a dropdown on that one is
 * that the interesting part is the *comparison*: a Poisson and a Pareto drawn
 * from the same seed, binned the same way, against their own analytic curves.
 * Shapes people have only ever seen as formulas turn out to be things you can
 * draw a thousand samples of in a few milliseconds.
 *
 * Every sampler is the method the literature actually recommends — Knuth and PTRS
 * for Poisson, Marsaglia–Tsang for Gamma, inverse CDF wherever one exists — and
 * they live in App\Random\Numbers\Distributions next to their own densities and
 * moments. Keeping the three together is what makes the claim checkable: the
 * histogram in the meta is measured, the curve beside it is analytic, and the
 * mean and variance underneath are both.
 *
 * Two of the eight are here because they misbehave. Cauchy has no mean at all, so
 * its sample mean wanders forever instead of settling; Pareto below α = 2 has
 * infinite variance. The panel reports "undefined" rather than a number, which is
 * the only honest thing to print and is far more memorable than the sentence in
 * the textbook.
 */
final class DistributionGenerator extends BaseGenerator
{
    /**
     * The widest histogram a discrete distribution gets.
     *
     * A Poisson at λ = 500 spreads over roughly ±100, and one bar per integer
     * would be a picture of a hedge. Past this many distinct outcomes the range
     * is grouped into equal-width bins like a continuous one — the bins stay
     * whole numbers of integers, so the expected counts stay exact.
     */
    private const MAX_DISCRETE_BINS = 60;

    /**
     * How many interquartile ranges past the quartiles a continuous histogram
     * reaches — Tukey's whisker, widened from the usual 1.5 to 3.
     *
     * Not min-to-max: one Cauchy draw in a thousand lands past ±300, and a plot
     * scaled to that is a single bar at the origin with blank paper either side.
     * A fixed quantile does not work either, because the tail that has to be cut
     * is a hundred times longer for a Cauchy than for a Beta. The IQR adapts to
     * the distribution's own scale, which is exactly what a box plot uses it for.
     * Three rather than 1.5 keeps about 99% of a bell-shaped sample on screen,
     * and whatever falls outside is counted rather than dropped.
     */
    private const WHISKER_IQRS = 3.0;

    /**
     * Sub-intervals per bin when integrating the density across it.
     *
     * The obvious shortcut — density at the bin centre, times the width — is only
     * right when the density is near-linear across a bin, and for a Cauchy whose
     * bins are six units wide around a peak one unit across it under-counts the
     * central bar by more than half. That reads as a sampler bug in the one plot
     * whose whole job is to show that the sampler is right. Composite Simpson
     * over sixteen panels costs 33 evaluations a bin and removes the question.
     */
    private const INTEGRATION_PANELS = 16;

    public function key(): string
    {
        return 'numbers.distribution';
    }

    public function name(): string
    {
        return 'Distribution Lab';
    }

    public function tagline(): string
    {
        return 'Poisson, Pareto, Zipf, Beta, Cauchy — sampled and plotted.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function renderer(): Renderer
    {
        return Renderer::Chart;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('distribution', 'Distribution', Distributions::labels(), default: 'poisson')
            ->int('count', 'How many', default: 1000, min: 10, max: 5000, help: 'Sampling noise falls as 1/√n. At a hundred draws the bars wobble visibly around the curve; at five thousand they sit on it.')
            ->float('lambda', 'Rate λ', default: 4.0, min: 0.01, max: 500.0, step: 0.5, help: 'Poisson: the mean number of events. Exponential: the arrival rate, so the mean wait is 1/λ.')
            ->float('alpha', 'Shape α', default: 2.0, min: 0.05, max: 50.0, step: 0.05, help: 'Pareto tail index, Gamma shape k, Beta’s first shape, and Zipf’s exponent s. Smaller is heavier-tailed.')
            ->float('beta', 'Shape β', default: 5.0, min: 0.05, max: 50.0, step: 0.05, help: 'Beta’s second shape. α = β = 1 is the uniform distribution.')
            ->float('scale', 'Scale', default: 1.0, min: 0.01, max: 100.0, step: 0.1, help: 'Gamma’s θ, Cauchy’s half-width γ, and Pareto’s minimum x_m — the point its tail starts from.')
            ->int('trials', 'Trials n', default: 40, min: 1, max: 500, help: 'Binomial only. Capped at 500 because the sampler inverts the CDF, which costs about n·p steps a draw.')
            ->float('p', 'Probability p', default: 0.3, min: 0.001, max: 0.999, step: 0.01, help: 'Binomial only: the chance each try succeeds.')
            ->int('support', 'Ranks N', default: 100, min: 2, max: 2000, help: 'Zipf only: how many ranks there are. Word frequency lists behave like this with s ≈ 1.')
            ->int('bins', 'Histogram bars', default: 30, min: 4, max: 60)
            ->int('decimals', 'Decimal places', default: 3, min: 0, max: 6);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $name = $params->string('distribution', 'poisson');
        $shape = $this->shapeParams($params);
        $discrete = Distributions::isDiscrete($name);

        $draw = Distributions::sampler($name, $shape);
        $count = $params->int('count');
        $decimals = $params->int('decimals');

        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $draw($rng);
            $values[] = $discrete ? (int) $x : round($x, $decimals);
        }

        $observed = $this->observedMoments($values);
        $analytic = Distributions::moments($name, $shape);

        return new Result(
            value: $values,
            display: implode(', ', array_map(
                fn (int|float $v): string => $discrete ? (string) $v : number_format((float) $v, $decimals, '.', ''),
                $values,
            )),
            meta: [
                'distribution' => Distributions::SPECS[$name]['label'],
                'parameters' => $this->parameterSummary($name, $shape),
                'histogram' => $discrete
                    ? $this->discreteHistogram($values, Distributions::density($name, $shape))
                    : $this->continuousHistogram($values, Distributions::density($name, $shape), $params->int('bins')),
                'observed_mean' => round($observed['mean'], max(3, $decimals)),
                'observed_variance' => round($observed['variance'], max(3, $decimals)),
                // Null, not a number, where the moment does not exist. Rounding
                // "undefined" to three places would be the exact false precision
                // these two distributions are on the list to disprove.
                'expected_mean' => $analytic['mean'] === null ? null : round($analytic['mean'], max(3, $decimals)),
                'expected_variance' => $analytic['variance'] === null ? null : round($analytic['variance'], max(3, $decimals)),
                // The running mean at a tenth of the sample and at all of it. For
                // every well-behaved distribution these two agree to a percent or
                // so; for Cauchy they routinely disagree by more than either one
                // of them, which is the whole lesson in two numbers.
                'mean_at_tenth' => round($this->meanOf(array_slice($values, 0, max(1, intdiv($count, 10)))), max(3, $decimals)),
                'undefined_moments' => $this->undefinedNote($analytic),
                'note' => Distributions::SPECS[$name]['note'],
                'method' => $this->methodNote($name, $shape),
            ],
        );
    }

    /**
     * The shared controls, narrowed to the ones this distribution reads.
     *
     * Ten sliders serving eight distributions means most of them are inert most
     * of the time. They are still all *drawn* from the schema — that is how the
     * panel works — but only the declared ones reach the sampler, so a Poisson
     * cannot silently change shape because someone dragged Beta's second
     * parameter.
     *
     * @return array<string, float|int>
     */
    private function shapeParams(Params $params): array
    {
        return [
            'lambda' => $params->float('lambda'),
            'alpha' => $params->float('alpha'),
            'beta' => $params->float('beta'),
            'scale' => $params->float('scale'),
            'trials' => $params->int('trials'),
            'p' => $params->float('p'),
            'support' => $params->int('support'),
        ];
    }

    /** @param array<string, float|int> $shape */
    private function parameterSummary(string $name, array $shape): string
    {
        $used = Distributions::SPECS[$name]['uses'];

        $labels = [
            'lambda' => 'λ', 'alpha' => 'α', 'beta' => 'β',
            'scale' => 'scale', 'trials' => 'n', 'p' => 'p', 'support' => 'N',
        ];

        return implode(' · ', array_map(
            fn (string $k): string => $labels[$k].' = '.(is_int($shape[$k]) ? $shape[$k] : round((float) $shape[$k], 3)),
            $used,
        ));
    }

    /**
     * One bar per outcome, or per group of outcomes when the support is wide.
     *
     * `expected` is n times the PMF summed across the bin — exact rather than a
     * density evaluated at the centre, because for a discrete distribution the
     * probability of a bin genuinely is the sum of the probabilities in it.
     */
    private function discreteHistogram(array $values, \Closure $pmf): array
    {
        $lo = (int) min($values);
        $hi = (int) max($values);
        $span = $hi - $lo + 1;
        $width = max(1, (int) ceil($span / self::MAX_DISCRETE_BINS));
        $bins = (int) ceil($span / $width);

        $counts = array_fill(0, $bins, 0);
        foreach ($values as $v) {
            $counts[intdiv((int) $v - $lo, $width)]++;
        }

        $n = count($values);
        $expected = [];

        for ($b = 0; $b < $bins; $b++) {
            $mass = 0.0;
            for ($k = $lo + $b * $width; $k < $lo + ($b + 1) * $width; $k++) {
                $mass += $pmf((float) $k);
            }
            $expected[] = round($n * $mass, 3);
        }

        return [
            'discrete' => true,
            'lo' => $lo,
            'hi' => $lo + $bins * $width,
            'width' => $width,
            'counts' => $counts,
            'expected' => $expected,
            'outside' => 0,
        ];
    }

    /** The same shape numbers.gaussian emits, so one chart renderer serves both. */
    private function continuousHistogram(array $values, \Closure $pdf, int $bins): array
    {
        $sorted = $values;
        sort($sorted);

        [$lo, $hi] = $this->plotRange($sorted);

        // A sample that landed on one value — a Beta with enormous shapes, a
        // Gamma with a tiny scale rounded to zero decimals — would otherwise give
        // a zero-width bin and divide by it.
        if ($hi <= $lo) {
            $hi = $lo + max(1e-9, abs($lo) * 0.01);
        }

        $width = ($hi - $lo) / $bins;
        $counts = array_fill(0, $bins, 0);
        $outside = 0;

        foreach ($values as $v) {
            $index = (int) floor(((float) $v - $lo) / $width);

            if ($index < 0 || $index >= $bins) {
                $outside++;

                continue;
            }

            $counts[$index]++;
        }

        $n = count($values);
        $expected = [];

        for ($i = 0; $i < $bins; $i++) {
            $expected[] = round($n * $this->mass($pdf, $lo + $i * $width, $lo + ($i + 1) * $width), 3);
        }

        return [
            'discrete' => false,
            'lo' => round($lo, 6),
            'hi' => round($hi, 6),
            'width' => round($width, 6),
            'counts' => $counts,
            'expected' => $expected,
            // The clipped tail, stated rather than hidden. For a Cauchy this is
            // several percent of the draws spread across a range hundreds of times
            // wider than the plot, which is the most honest possible label for a
            // fat tail.
            'outside' => $outside,
        ];
    }

    /**
     * Tukey's whiskers, clipped to the sample's own extent.
     *
     * @param  list<float|int>  $sorted
     * @return array{0: float, 1: float}
     */
    private function plotRange(array $sorted): array
    {
        $q1 = $this->quantile($sorted, 0.25);
        $q3 = $this->quantile($sorted, 0.75);
        $reach = self::WHISKER_IQRS * ($q3 - $q1);

        return [
            max((float) $sorted[0], $q1 - $reach),
            min((float) $sorted[count($sorted) - 1], $q3 + $reach),
        ];
    }

    /** @param list<float|int> $sorted */
    private function quantile(array $sorted, float $q): float
    {
        $position = $q * (count($sorted) - 1);
        $index = (int) floor($position);
        $next = min(count($sorted) - 1, $index + 1);

        // Linear interpolation between the two neighbouring order statistics,
        // which is what every stats package means by a quantile and matters for
        // small samples where the two differ noticeably.
        return (float) $sorted[$index] + ($position - $index) * ((float) $sorted[$next] - (float) $sorted[$index]);
    }

    /** ∫ pdf over one bin, by composite Simpson. */
    private function mass(\Closure $pdf, float $from, float $to): float
    {
        $h = ($to - $from) / self::INTEGRATION_PANELS;
        $sum = $pdf($from) + $pdf($to);

        for ($i = 1; $i < self::INTEGRATION_PANELS; $i++) {
            $sum += $pdf($from + $i * $h) * ($i % 2 === 1 ? 4 : 2);
        }

        return $sum * $h / 3;
    }

    /** @return array{mean: float, variance: float} */
    private function observedMoments(array $values): array
    {
        $mean = $this->meanOf($values);
        $sum = 0.0;

        foreach ($values as $v) {
            $sum += ((float) $v - $mean) ** 2;
        }

        return [
            'mean' => $mean,
            // n−1: the mean was estimated from this same sample, so one degree of
            // freedom has already been spent.
            'variance' => count($values) > 1 ? $sum / (count($values) - 1) : 0.0,
        ];
    }

    private function meanOf(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /** @param array{mean: ?float, variance: ?float} $analytic */
    private function undefinedNote(array $analytic): ?string
    {
        if ($analytic['mean'] === null) {
            return 'This distribution has no mean and no variance. The two numbers above were computed from the sample and are not estimates of anything — draw again and they will move by more than their own size.';
        }

        if ($analytic['variance'] === null) {
            return 'The mean exists; the variance is infinite. The sample variance is finite only because the sample is, and it grows every time you add draws.';
        }

        return null;
    }

    /** @param array<string, float|int> $shape */
    private function methodNote(string $name, array $shape): string
    {
        return match ($name) {
            'poisson' => (float) $shape['lambda'] < 30
                ? 'Knuth: multiply uniforms until the product falls below e^(−λ). Exact, and costs about λ + 1 draws.'
                : 'PTRS transformed rejection — Knuth’s loop is linear in λ, and above λ ≈ 30 that stops being free.',
            'binomial' => 'Inverse CDF, walking the PMF upward by its recurrence. p above ½ is reflected onto 1 − p, which halves the walk.',
            'zipf' => 'Inverse CDF by binary search over the exact cumulative table — the support is bounded, so there is no reason to approximate it.',
            'exponential' => 'Inverse CDF: X = −ln(1 − U)/λ.',
            'gamma' => 'Marsaglia–Tsang: one normal and one uniform per attempt, accepted about 98% of the time. Shapes below 1 use the G(α+1)·U^(1/α) boost.',
            'beta' => 'Two Marsaglia–Tsang Gammas, G₁/(G₁ + G₂). Exact, with no rejection step of its own.',
            'pareto' => 'Inverse CDF: X = x_m / U^(1/α).',
            default => 'Inverse CDF: X = γ·tan(π(U − ½)) — the tangent is what makes the tails fat.',
        };
    }
}
