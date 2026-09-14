<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * A mesh gradient: a handful of coloured control points, blended over the plane.
 *
 *   colour(p) = Σ wᵢ · colourᵢ / Σ wᵢ,   wᵢ = exp(−|p − cᵢ|²/2σ²)  or  |p − cᵢ|^(−k)
 *
 * Two weightings, and they do not look remotely alike. Gaussian falls off
 * smoothly and gives the soft liquid wash the phrase usually means. Shepard's is
 * infinite at each control point, so every point holds its own colour exactly and
 * the field between them develops flat interiors and taut boundaries — closer to
 * stained glass than to a gradient.
 *
 * **The dither is the point of this generator.** A gradient is the one thing
 * eight bits per channel genuinely cannot represent: a channel moving through
 * forty levels across 900 pixels means forty twenty-pixel bands with hard edges
 * between them, and the eye's lateral inhibition makes those edges far louder
 * than the 1/255 step that causes them. Offsetting each pixel by less than one
 * level before the round moves the decision point around, and the bands dissolve
 * into a texture finer than the display can resolve. It is one line of
 * arithmetic, it is switchable so the difference can be seen, and it is the whole
 * distance between output that looks cheap and output that looks designed
 * (docs/08 §1).
 *
 * Control point positions are stored as fractions of the canvas rather than as
 * pixels, so the same seed renders the same composition at any size — the layout
 * belongs to the seed, not to the output resolution.
 */
final class GradientGenerator extends ImageGenerator
{
    public function key(): string
    {
        return 'images.gradient';
    }

    public function name(): string
    {
        return 'Mesh Gradient';
    }

    public function tagline(): string
    {
        return 'Random control points, dithered to kill the banding.';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 1200, 800)
            ->int('stops', 'Control points', default: 10, min: 2, max: 18, help: 'Each one holds a colour and pulls the field towards it.')
            ->enum('blend', 'Blend', [
                'soft' => 'Gaussian (soft wash)',
                'sharp' => 'Shepard (stained glass)',
            ], default: 'soft')
            ->float('falloff', 'Softness', default: 0.42, min: 0.18, max: 1.8, step: 0.02, help: 'The Gaussian width, measured in the typical spacing between control points rather than in pixels — so adding points does not also change how soft the blend is.')
            ->float('power', 'Shepard exponent', default: 2.6, min: 1.0, max: 8.0, step: 0.1, help: 'Only used by the stained-glass blend. Higher values flatten the cell interiors and tighten the boundaries between them.')
            ->float('warp', 'Domain warp', default: 0.18, min: 0.0, max: 0.6, step: 0.01, help: 'Displaces each pixel through a noise field before the blend is evaluated. The isolines stop being circles and start folding around each other, which is what makes a gradient look poured rather than computed.')
            ->float('warp_scale', 'Warp zoom', default: 2.2, min: 0.4, max: 8.0, step: 0.1)
            ->float('dither', 'Dither', default: 1.0, min: 0.0, max: 4.0, step: 0.1, help: 'Ordered dither amplitude, in output levels. One level is the right answer; zero is there so you can see the banding it prevents.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'split')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.006, min: 0.0, max: 0.06, step: 0.002, help: 'Film grain over the finished render, on top of the dither. The two do different jobs: the dither hides quantisation, the grain gives the surface a texture.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('colours', 6);

        /*
         * ramp(), not build(). Every pixel here is a weighted average of several
         * stops at once, so the palette is interpolated continuously and in two
         * dimensions — the single worst place to put categorical colours, where
         * a blend of three hues 120° apart would land in grey mud over most of
         * the canvas.
         */
        $palette = Palette::ramp($rng, $params->string('palette', 'split'), $count);

        $stops = $this->stops($rng, $params->int('stops'), $count);

        $spec = [
            'algorithm' => 'gradient',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'stops' => $stops,
            'blend' => $params->string('blend', 'soft'),
            'falloff' => $params->float('falloff'),
            'power' => $params->float('power'),
            'warp' => $params->float('warp'),
            'warp_scale' => $params->float('warp_scale'),
            'dither' => $params->float('dither'),
            'palette' => $palette,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%d control points · %s · %s · %s',
                count($stops),
                $params->string('blend', 'soft') === 'sharp' ? 'Shepard' : 'Gaussian',
                $params->float('warp') > 0 ? sprintf('warp %.2f', $params->float('warp')) : 'no warp',
                $params->float('dither') > 0 ? sprintf('%.1f-level dither', $params->float('dither')) : 'undithered',
            ),
            meta: [
                'palette' => $palette,
                'stops' => count($stops),
                // What the dither is up against: how many distinct values a
                // channel can take between the palette's extremes. Under a
                // hundred is where banding becomes obvious, and a smooth
                // gradient is almost always under a hundred.
                'output_levels' => 256,
                'note' => $params->float('dither') > 0
                    ? 'Each pixel is offset by less than one output level, from an 8×8 Bayer matrix, before being rounded to eight bits. That moves the rounding decision from pixel to pixel, so the twenty-pixel bands a smooth gradient would otherwise show break up into a texture finer than a display can resolve. Turn it off to see what it is doing.'
                    : 'Dither is off, so every channel rounds to its nearest of 256 levels with no offset. On a gradient this shows as flat bands with hard edges — an artefact of the output format, not of the maths, and the reason the dither control exists.',
            ],
        );
    }

    /**
     * Place the control points, and give each one a colour.
     *
     * Two things are deliberate. Positions are drawn over a slightly *larger*
     * rectangle than the canvas, so some stops sit off the edge — a composition
     * whose every control point is visible reads as a diagram of its own
     * construction, and the off-frame stops are what make the colour appear to
     * arrive from somewhere. And the colours walk the palette in order with a
     * random start rather than being drawn independently, so neighbouring stops
     * are usually neighbouring hues and the field never has to blend across the
     * full range in one step.
     *
     * @return list<array{float, float, int}> x, y as fractions of the canvas, palette index
     */
    private function stops(Rng $rng, int $count, int $colours): array
    {
        $stops = [];
        $index = $rng->intBetween(0, $colours - 1);
        $direction = $rng->bool() ? 1 : -1;

        for ($i = 0; $i < $count; $i++) {
            $stops[] = [
                round(-0.14 + $rng->float() * 1.28, 5),
                round(-0.14 + $rng->float() * 1.28, 5),
                $index,
            ];

            // A step of one most of the time, two occasionally: a strict walk
            // makes the field too even, and a free draw puts the darkest stop
            // next to the lightest often enough to mud the middle.
            $index = ($index + $direction * ($rng->bool(0.78) ? 1 : 2) + $colours * 2) % $colours;
        }

        return $stops;
    }
}
