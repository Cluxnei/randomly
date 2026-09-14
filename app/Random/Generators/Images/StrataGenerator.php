<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Layered bands, with their heights drawn from a Dirichlet distribution.
 *
 *   h ~ Dir(α, …, α),   hᵢ = Gᵢ / ΣG,  Gᵢ ~ Gamma(α, 1)
 *
 * The Dirichlet is the whole design, and the reason is that it is the *only*
 * natural way to draw a set of heights that sum to one. The obvious alternatives
 * both fail in ways you can see: independent uniforms normalised afterwards
 * concentrate hard around equal heights, because normalising a sum of
 * independent draws is an averaging operation, and cutting the interval at n−1
 * uniform points gives the Dirichlet with α = 1 and nothing else. Only α makes
 * the *character* of the stack a control.
 *
 * Below 1 a few bands take almost everything and the rest are hairlines — the
 * look of a cliff face with one massive sandstone bed. At 1 the heights are as
 * unstructured as heights can be. Above 3 every band converges on the same
 * height, which is a colour chart. The slider runs across all three.
 *
 * Everything else here exists to stop the result reading as a bar chart.
 * Boundaries wander, each with its own amplitude and frequency, so bands pinch
 * out and reappear; and the colours walk the palette in small steps rather than
 * being drawn independently, because adjacent rock layers are related and
 * adjacent random hues are not.
 */
final class StrataGenerator extends ImageGenerator
{
    public function key(): string
    {
        return 'images.strata';
    }

    public function name(): string
    {
        return 'Strata';
    }

    public function tagline(): string
    {
        return 'Band heights from a Dirichlet draw, colours walking a palette.';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 1000, 1300)
            ->int('bands', 'Bands', default: 22, min: 2, max: 70)
            ->float('concentration', 'Dirichlet α', default: 0.85, min: 0.15, max: 6.0, step: 0.05, help: 'Below 1, a few bands take almost everything and the rest are hairlines. At 1 the heights are as unstructured as heights can be. Above 3 they all converge on the same thickness, which is a colour chart rather than a cliff.')
            ->float('roughness', 'Boundary wander', default: 0.05, min: 0.0, max: 0.3, step: 0.005, help: 'How far a boundary strays from its nominal height, as a fraction of the canvas. Zero draws a bar chart; this is the single control that makes it rock.')
            ->float('frequency', 'Wander scale', default: 2.6, min: 0.3, max: 12.0, step: 0.1, help: 'How many undulations fit across the canvas.')
            ->int('octaves', 'Boundary detail', default: 4, min: 1, max: 7, help: 'Octaves in the noise displacing each boundary. One is a smooth wave; more adds the fine crumbling of a real edge.')
            ->float('tilt', 'Tilt', default: 0.05, min: 0.0, max: 0.35, step: 0.005, help: 'A linear slope added to each boundary, drawn independently per band — so the stack shears the way tilted bedding does.')
            ->float('shade', 'Within-band shading', default: 0.07, min: -0.25, max: 0.25, step: 0.005, help: 'How far along the palette a band travels between its own top and bottom. Flat fills read as coloured strips; a gradient reads as a surface with light on it.')
            ->float('walk', 'Colour step', default: 0.11, min: 0.01, max: 0.5, step: 0.01, help: 'How far the colour moves between one band and the next. Small steps keep neighbouring layers related, which is what makes the stack read as one formation.')
            ->enum('background', 'Ground', self::GROUNDS, default: 'ink')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'magma')
            ->int('colours', 'Palette size', default: 8, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.02, min: 0.0, max: 0.1, step: 0.002, help: 'Film grain over the finished render. Strata want more of it than most things here — flat coloured areas are exactly where 8-bit output shows its seams.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        /*
         * ramp(), not build(). The colours walk along the palette in small
         * steps and every band is additionally shaded between two points on it,
         * so the palette is sampled continuously at almost every pixel. This is
         * the case ramp() exists for.
         */
        $palette = Palette::ramp($rng, $params->string('palette', 'magma'), $params->int('colours', 8));

        $background = $this->ground($params->string('background', 'ink'), $palette);
        $bands = $this->bands($rng, $params);

        $spec = [
            'algorithm' => 'strata',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'bands' => $bands,
            'frequency' => $params->float('frequency'),
            'octaves' => $params->int('octaves'),
            'shade' => $params->float('shade'),
            'background' => $background,
            'palette' => $palette,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
        ];

        $heights = array_map(fn (array $b): float => $b[0], $bands);

        return new Result(
            value: $spec,
            display: sprintf(
                '%d bands · Dir(α = %.2f) · thickest %.1f%% of the height · %d thinner than a pixel',
                count($bands),
                $params->float('concentration'),
                max($heights) * 100,
                count(array_filter($heights, fn (float $h): bool => $h * $params->int('height') < 1.0)),
            ),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'bands' => count($bands),
                'concentration' => $params->float('concentration'),
                // What the α slider is actually controlling, as one number. An
                // even stack gives every band 1/n of the height; a ragged one
                // gives its thickest band most of it. Reported as a share rather
                // than as a thickest-to-thinnest ratio, because below α = 1 the
                // thinnest band is a rounding error away from zero and the ratio
                // becomes a number about floating point.
                'thickest_share' => sprintf('%.1f%% (even would be %.1f%%)', max($heights) * 100, 100 / max(1, count($heights))),
                'note' => sprintf(
                    'Heights are one draw from a symmetric Dirichlet with α = %.2f, built the standard way: one Gamma(α, 1) per band, normalised by their sum. Every boundary is then displaced by its own fractal noise and its own tilt, so bands pinch out and reappear rather than running parallel — and where two boundaries would cross, the lower one is clamped to the upper and the band between them simply runs out, which is what a real stratum does.',
                    $params->float('concentration'),
                ),
            ],
        );
    }

    /**
     * The bands themselves.
     *
     * @return list<array{float, float, float, float, float}> height, colour t, wander, phase, tilt
     */
    private function bands(Rng $rng, Params $params): array
    {
        $count = $params->int('bands', 22);
        $alpha = $params->float('concentration');

        $gammas = [];
        for ($i = 0; $i < $count; $i++) {
            $gammas[] = self::gamma($rng, $alpha);
        }

        $total = array_sum($gammas) ?: 1.0;

        /*
         * Colour is a walk along the ramp, not a draw from it.
         *
         * The walk reflects off both ends rather than wrapping. Wrapping would
         * put the palette's lightest stop directly against its darkest every time
         * the walk went round, which is a hard seam across the picture at a
         * position nothing in the geology chose.
         */
        $t = $rng->float();
        $direction = $rng->bool() ? 1.0 : -1.0;
        $walk = $params->float('walk');

        $bands = [];

        for ($i = 0; $i < $count; $i++) {
            $bands[] = [
                // Eight places, not six. At α = 0.2 a Dirichlet draw over thirty
                // bands routinely produces heights below 1e-6, and rounding those
                // to zero loses the very bands the low end of the slider exists
                // to produce.
                round($gammas[$i] / $total, 8),
                round($t, 5),
                // Per band, so boundaries wander independently and the stack does
                // not look like one waveform drawn many times.
                round($params->float('roughness') * (0.35 + $rng->float() * 1.3), 5),
                round($rng->float() * 64.0, 4),
                round($params->float('tilt') * ($rng->float() * 2 - 1), 5),
            ];

            $t += $direction * $walk * (0.5 + $rng->float());

            if ($t > 1.0) {
                $t = 2.0 - $t;
                $direction = -1.0;
            } elseif ($t < 0.0) {
                $t = -$t;
                $direction = 1.0;
            }
        }

        return $bands;
    }

    /**
     * One Gamma(shape, 1) draw, by Marsaglia–Tsang.
     *
     * The cheap squeeze test in the middle is what makes this a loop that almost
     * always exits on its first pass: for a shape at or above 1 the acceptance
     * rate is over 95%, and the polynomial test catches most of that before the
     * logarithm is reached.
     */
    private static function gamma(Rng $rng, float $shape): float
    {
        // The method needs d = shape − 1/3 to be positive, so a shape below 1 is
        // boosted into range and scaled back by the standard u^(1/shape) factor.
        if ($shape < 1.0) {
            return self::gamma($rng, $shape + 1.0) * max(1e-12, $rng->float()) ** (1.0 / $shape);
        }

        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);

        while (true) {
            $z = $rng->gaussian();
            $v = (1.0 + $c * $z) ** 3;

            if ($v <= 0) {
                continue;
            }

            $u = $rng->float();

            if ($u < 1.0 - 0.0331 * $z ** 4) {
                return $d * $v;
            }

            if (log(max(1e-300, $u)) < 0.5 * $z ** 2 + $d * (1.0 - $v + log($v))) {
                return $d * $v;
            }
        }
    }
}
