<?php

declare(strict_types=1);

namespace App\Random\Generators\Numbers;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * The normal distribution, by Box–Muller.
 *
 * Two uniform draws in, two independent standard normals out:
 *
 *     Z₀ = √(−2 ln U₁) · cos(2π U₂)      Z₁ = √(−2 ln U₁) · sin(2π U₂)
 *
 * The transform is exact rather than approximate — no summing twelve uniforms and
 * calling the central limit theorem close enough, which is the folk method and
 * which truncates the tails at ±6σ. Rng::gaussian caches Z₁, so the average cost
 * is one uniform pair per two values.
 *
 * The histogram in the meta is the point of the generator. A list of five hundred
 * numbers is unreadable; the same five hundred numbers as bars against the
 * analytic curve is immediately a bell, and the places where the bars sit proud of
 * the curve are exactly the sampling noise the count control is there to shrink.
 */
final class GaussianGenerator extends BaseGenerator
{
    /**
     * How far either side of the mean the histogram reaches.
     *
     * Four sigma covers 99.9937% of the distribution, so the plot has visible
     * tails without spending most of its width on empty space. Values beyond it
     * are counted into the end bins and reported separately rather than dropped.
     */
    private const HISTOGRAM_SIGMAS = 4.0;

    public function key(): string
    {
        return 'numbers.gaussian';
    }

    public function name(): string
    {
        return 'Bell Curve';
    }

    public function tagline(): string
    {
        return 'The normal distribution by Box–Muller, plotted as you draw it.';
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
            ->int('count', 'How many', default: 500, min: 1, max: 5000)
            ->float('mu', 'Mean (μ)', default: 0.0, min: -1_000_000.0, max: 1_000_000.0, step: 0.5)
            ->float('sigma', 'Standard deviation (σ)', default: 1.0, min: 0.001, max: 1_000_000.0, step: 0.1)
            ->bool('clamp', 'Truncate the tails', help: 'Redraw anything beyond the cut instead of keeping it. This is a truncated normal, not a normal — the shape changes.')
            ->float('clamp_sigma', 'Cut at (σ)', default: 3.0, min: 1.0, max: 6.0, step: 0.5)
            ->int('bins', 'Histogram bars', default: 25, min: 4, max: 60)
            ->int('decimals', 'Decimal places', default: 3, min: 0, max: 6);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('count');
        $mu = $params->float('mu');
        $sigma = $params->float('sigma');
        $decimals = $params->int('decimals');

        $clamp = $params->bool('clamp');
        $cut = $params->float('clamp_sigma') * $sigma;
        $redraws = 0;

        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $x = $rng->gaussian($mu, $sigma);

            // Rejection, not clipping. Clipping to ±kσ would pile every tail draw
            // onto the two boundary values and leave a spike at each end of the
            // histogram that no normal distribution has; redrawing gives the
            // genuine truncated normal, and the redraw count is reported so the
            // cost of the truncation is on screen rather than hidden.
            while ($clamp && abs($x - $mu) > $cut) {
                $redraws++;
                $x = $rng->gaussian($mu, $sigma);
            }

            $values[] = round($x, $decimals);
        }

        // Every statistic below is computed on the rounded values, so what the
        // meta reports is a measurement of what the user was actually given.
        $mean = array_sum($values) / $count;
        $sd = $this->standardDeviation($values, $mean);

        return new Result(
            value: $values,
            display: implode(', ', array_map(fn (float $v): string => number_format($v, $decimals, '.', ''), $values)),
            meta: [
                'histogram' => $this->histogram($values, $mu, $sigma, $params->int('bins'), $clamp ? $cut : null),
                'observed_mean' => round($mean, max(3, $decimals)),
                'observed_sigma' => round($sd, max(3, $decimals)),
                // σ/√n — how far the sample mean is expected to sit from μ. Without
                // it "the mean came out 0.043, not 0" reads as a bug rather than as
                // the entirely ordinary noise it is.
                'standard_error' => round($sigma / sqrt($count), max(3, $decimals)),
                'within_sigma' => $this->coverage($values, $mu, $sigma),
                'expected_within_sigma' => ['1' => 0.6827, '2' => 0.9545, '3' => 0.9973],
                'truncated' => $clamp,
                'redraws' => $redraws,
                'note' => $clamp
                    ? sprintf('Truncated at ±%.1fσ by redrawing, which cost %d extra draws. The result is a truncated normal: its true standard deviation is smaller than the σ you asked for, because the tails that carried the extra spread are gone.', $params->float('clamp_sigma'), $redraws)
                    : 'Box–Muller: two uniform draws become two independent normals, exactly rather than approximately. The second of each pair is cached, so a thousand values cost about a thousand uniforms.',
            ],
        );
    }

    /** Sample standard deviation — n−1, because μ was estimated from the same data. */
    private function standardDeviation(array $values, float $mean): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return sqrt($sum / (count($values) - 1));
    }

    /**
     * Bars plus the curve they should be following.
     *
     * `expected` holds the count a perfect sample of this size would put in each
     * bin — the integral of the density across the bin, approximated at its centre,
     * times n times the bin width. Emitting it here rather than letting the client
     * re-derive it keeps the analytic truth and the sample in the same payload,
     * which is what makes the overlay trustworthy.
     */
    private function histogram(array $values, float $mu, float $sigma, int $bins, ?float $cut): array
    {
        $reach = $cut ?? self::HISTOGRAM_SIGMAS * $sigma;
        $lo = $mu - $reach;
        $hi = $mu + $reach;
        $width = ($hi - $lo) / $bins;

        $counts = array_fill(0, $bins, 0);
        $outside = 0;

        foreach ($values as $v) {
            $index = (int) floor(($v - $lo) / $width);

            if ($index < 0 || $index >= $bins) {
                $outside++;
                $index = max(0, min($bins - 1, $index));
            }

            $counts[$index]++;
        }

        $n = count($values);
        $expected = [];

        for ($i = 0; $i < $bins; $i++) {
            $centre = $lo + ($i + 0.5) * $width;
            $density = exp(-(($centre - $mu) ** 2) / (2 * $sigma ** 2)) / ($sigma * sqrt(2 * M_PI));
            $expected[] = round($n * $density * $width, 3);
        }

        return [
            'lo' => round($lo, 6),
            'hi' => round($hi, 6),
            'width' => round($width, 6),
            'counts' => $counts,
            'expected' => $expected,
            'outside' => $outside,
        ];
    }

    /** @return array<string, float> the fraction landing within 1, 2 and 3 sigma */
    private function coverage(array $values, float $mu, float $sigma): array
    {
        $bands = ['1' => 0, '2' => 0, '3' => 0];

        foreach ($values as $v) {
            $z = abs($v - $mu) / $sigma;
            foreach ([1, 2, 3] as $k) {
                if ($z <= $k) {
                    $bands[(string) $k]++;
                }
            }
        }

        return array_map(fn (int $c): float => round($c / count($values), 4), $bands);
    }
}
