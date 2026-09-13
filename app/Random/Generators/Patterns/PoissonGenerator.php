<?php

declare(strict_types=1);

namespace App\Random\Generators\Patterns;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Blue noise, by Bridson's algorithm — beside the same number of uniform random
 * points, because the comparison is the whole generator.
 *
 * Uniform random points look wrong. Not subtly: they clump into knots and leave
 * holes you could park a car in, and everybody's first reaction on seeing them is
 * that the random number generator must be broken. It is not — clumps are exactly
 * what independence produces, and a scatter with no clumps in it would be the
 * suspicious one. The eye simply expects "random" to mean "evenly spread", and
 * those are different things.
 *
 * Blue noise is what people actually wanted: points that are random but with a
 * guaranteed minimum separation r, so no clumps and no holes. Bridson's algorithm
 * gets there in O(n) with a background grid of cells of side r/√2 — small enough
 * that a cell holds at most one point, so a candidate only ever has to be checked
 * against its twenty-five neighbouring cells rather than against every point
 * already placed.
 *
 * It is what stipple shading, dithering, sensor layouts and every sampler in a
 * renderer since about 2005 use, and the reason is visible the instant the two
 * panels sit side by side.
 */
final class PoissonGenerator extends BaseGenerator
{
    public function key(): string
    {
        return 'patterns.poisson';
    }

    public function name(): string
    {
        return 'Blue Noise';
    }

    public function tagline(): string
    {
        return 'Poisson-disk beside uniform random — the difference is the lesson.';
    }

    public function module(): Module
    {
        return Module::Patterns;
    }

    public function renderer(): Renderer
    {
        return Renderer::Canvas;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('width', 'Width', default: 900, min: 200, max: 2048)
            ->int('height', 'Height', default: 600, min: 200, max: 2048)
            ->enum('mode', 'Show', [
                'compare' => 'Both, side by side',
                'blue' => 'Blue noise only',
                'uniform' => 'Uniform random only',
            ], default: 'compare')
            ->float('radius', 'Minimum separation', default: 17.0, min: 5.0, max: 90.0, step: 0.5, help: 'In pixels. No two blue-noise points are ever closer than this; the uniform panel gets the same number of points and no such promise.')
            ->int('candidates', 'Candidates per point', default: 30, min: 4, max: 60, help: "Bridson's k. Each accepted point gets this many attempts at a neighbour before it is retired. Low values leave gaps.")
            ->float('dot', 'Dot size', default: 3.0, min: 0.6, max: 12.0, step: 0.2)
            ->enum('colouring', 'Colour dots by', [
                'nearest' => 'Distance to nearest neighbour',
                'flat' => 'Flat',
            ], default: 'nearest', help: 'The measurement that separates the two panels: blue noise has almost none of it, uniform random has all of it.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'magma')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // ramp(), not build(): the dots are coloured by their nearest-neighbour
        // distance, which is a continuous measurement and wants a continuous scale.
        $palette = Palette::ramp($rng, $params->string('palette', 'magma'), $params->int('colours', 7));

        $mode = $params->string('mode', 'compare');
        $panels = $mode === 'compare' ? 2 : 1;

        $gutter = 14;
        $panelWidth = ($params->int('width') - $gutter * ($panels + 1)) / $panels;
        $panelHeight = $params->int('height') - $gutter * 2;
        $radius = $params->float('radius');

        // Bridson's sampling burns two doubles per candidate and tries up to k of
        // them per accepted point, which is tens of thousands of draws for a dense
        // field — far past the 8 KB the render stream carries. So one uint32 goes
        // across and the browser expands it locally; see resources/js/patterns/prng.js.
        $seed = $rng->uint32();

        $spec = [
            'algorithm' => 'poisson',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'mode' => $mode,
            'radius' => $radius,
            'candidates' => $params->int('candidates'),
            'dot' => $params->float('dot'),
            'colouring' => $params->string('colouring', 'nearest'),
            'gutter' => $gutter,
            'palette' => $palette,
            'seed' => $seed,
        ];

        return new Result(
            value: $spec,
            display: $this->describe($mode, $radius, $panelWidth, $panelHeight),
            meta: [
                'palette' => $palette,
                // Bridson's packing sits a little under the hexagonal ideal, which
                // would be 2A/(√3 r²). The 0.72 is measured from this
                // implementation at k=30 rather than derived, and it is an estimate:
                // the browser is what actually decides how many points fit.
                'estimated_points' => (int) round(0.72 * 2 * $panelWidth * $panelHeight / (sqrt(3) * $radius ** 2)),
                'guarantee' => sprintf('No two blue-noise points closer than %.1f px.', $radius),
                'cell_size' => round($radius / M_SQRT2, 2),
                'note' => $mode === 'compare'
                    ? sprintf(
                        'Both panels hold the same number of points and both are random. The left one keeps a minimum separation of %.1f px; the right one keeps no promise at all, which is why it clumps and leaves holes. The clumping is not a fault in the randomness — independent points clump, and a scatter without clumps would be the suspicious one. It is simply that "random" and "evenly spread" are different things, and most of the time what people want is the second.',
                        $radius,
                    )
                    : sprintf('%s, at a minimum separation of %.1f px. Bridson\'s background grid has cells of r/√2 = %.2f px, small enough to hold at most one point each, which is what makes the whole thing linear rather than quadratic.', $mode === 'blue' ? 'Poisson-disk sampling' : 'Uniform random points', $radius, $radius / M_SQRT2),
            ],
        );
    }

    private function describe(string $mode, float $radius, float $panelWidth, float $panelHeight): string
    {
        return sprintf(
            '%s · r = %.1f px · %d × %d per panel',
            match ($mode) {
                'blue' => 'Poisson-disk (blue noise)',
                'uniform' => 'Uniform random',
                default => 'Blue noise (left) vs uniform random (right)',
            },
            $radius,
            (int) round($panelWidth),
            (int) round($panelHeight),
        );
    }
}
