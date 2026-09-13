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
 * Uniform random points on the surface of the Earth — and the bug almost every
 * implementation of this ships.
 *
 * Drawing `lat ~ U(-90, 90)` looks obviously right and is obviously wrong. A
 * latitude band of fixed angular height does not have fixed area: the band from
 * 89° to 90° is a coin-sized cap, and the band from 0° to 1° is a belt around the
 * whole planet. Their areas differ by a factor of cos(lat), so sampling latitude
 * uniformly puts the same number of points in both and the poles end up matted
 * with points while the equator goes bare.
 *
 * The fix is one inverse-CDF away. Area on a sphere is uniform in sin(lat), not
 * in lat, so draw sin(lat) uniformly and invert:
 *
 *     lat = asin(2u − 1) · 180/π        lon = 360v − 180
 *
 * Longitude needs no correction — meridians are all the same length.
 *
 * Both sets are always emitted, from *the same* u and v draws, so the comparison
 * is honest: the only thing that differs between the left globe and the right one
 * is the transform applied to u. Nothing about the randomness changed.
 */
final class CoordinatesGenerator extends BaseGenerator
{
    /**
     * The cap boundary the meta statistic is measured against.
     *
     * 60° is chosen because the arithmetic is exact and quotable: everything above
     * it is 1 − sin 60° = 13.4% of the Earth's surface, while it is a full third
     * of the latitude *range*. So the naive sampler lands about 33% of its points
     * there and the correct one about 13%, and the gap between those two numbers
     * is the whole bug expressed as a single measurement.
     */
    private const CAP_DEGREES = 60.0;

    public function key(): string
    {
        return 'numbers.coordinates';
    }

    public function name(): string
    {
        return 'Earth Points';
    }

    public function tagline(): string
    {
        return 'Uniform on a sphere — not the latitude bug everyone ships.';
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
            ->int('count', 'How many points', default: 150, min: 1, max: 1000)
            ->enum('show', 'Show', [
                'compare' => 'Both, side by side',
                'correct' => 'Correct only',
                'naive' => 'The bug only',
            ], default: 'compare', help: 'Both sets are always generated. This only chooses what is plotted.')
            ->int('decimals', 'Decimal places', default: 4, min: 0, max: 6, help: 'Four places is about 11 metres at the equator.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('count');
        $decimals = $params->int('decimals');

        $correct = [];
        $naive = [];

        for ($i = 0; $i < $count; $i++) {
            $u = $rng->float();
            $v = $rng->float();

            // One longitude, two latitudes, one pair of draws. Giving each set its
            // own randomness would make the two globes differ for two reasons at
            // once, and the point of the picture is that only one of them moved.
            $lon = round(360.0 * $v - 180.0, $decimals);

            $correct[] = ['lat' => round(rad2deg(asin(2.0 * $u - 1.0)), $decimals), 'lon' => $lon];
            $naive[] = ['lat' => round(180.0 * $u - 90.0, $decimals), 'lon' => $lon];
        }

        $show = $params->string('show', 'compare');

        return new Result(
            value: ['correct' => $correct, 'naive' => $naive],
            display: $this->describe($show === 'naive' ? $naive : $correct, $decimals),
            meta: [
                'count' => $count,
                'correct_method' => 'lat = asin(2u − 1) · 180/π, lon = 360v − 180',
                'naive_method' => 'lat = 180u − 90, lon = 360v − 180',
                'polar_share_correct' => round($this->capShare($correct), 4),
                'polar_share_naive' => round($this->capShare($naive), 4),
                // The truth both are being measured against: the fraction of the
                // sphere's *area* lying above 60° in either hemisphere.
                'polar_share_expected' => round(1.0 - sin(deg2rad(self::CAP_DEGREES)), 4),
                // Stated as theory first and observation second, rather than the
                // other way round. At six points both samplers will often put none
                // at all above 60°, and a note that leads with two zeroes reads as
                // though there were nothing to see.
                'note' => sprintf(
                    'Above %d° of latitude lies %.1f%% of the Earth\'s surface — but a third of its latitude range. So sampling latitude uniformly puts roughly a third of its points up there, matting the poles while the equator goes bare: it gives a coin-sized polar cap the same number of points as a belt around the whole planet. Sampling sin(latitude) instead puts %.1f%% there, which is the truth. In this draw of %d: %d correct against %d naive. Longitude needs no such correction — every meridian is the same length.',
                    (int) self::CAP_DEGREES,
                    (1.0 - sin(deg2rad(self::CAP_DEGREES))) * 100,
                    (1.0 - sin(deg2rad(self::CAP_DEGREES))) * 100,
                    $count,
                    (int) round($this->capShare($correct) * $count),
                    (int) round($this->capShare($naive) * $count),
                ),
                // Two doubles per point, each carrying 53 bits, but the honest
                // figure is what a reader could distinguish: 10^decimals steps per
                // degree across 180° of latitude and 360° of longitude.
                'entropy_out_bits' => round($count * log(180 * 360 * (10 ** $decimals) ** 2, 2), 2),
            ],
        );
    }

    /** What fraction of a point set landed in the two polar caps. */
    private function capShare(array $points): float
    {
        if ($points === []) {
            return 0.0;
        }

        $inside = 0;
        foreach ($points as $point) {
            if (abs($point['lat']) >= self::CAP_DEGREES) {
                $inside++;
            }
        }

        return $inside / count($points);
    }

    private function describe(array $points, int $decimals): string
    {
        $format = "%+.{$decimals}f, %+.{$decimals}f";

        return implode("\n", array_map(
            fn (array $p): string => sprintf($format, $p['lat'], $p['lon']),
            $points,
        ));
    }
}
