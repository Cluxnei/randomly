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
 * Voronoi cells: every pixel belongs to whichever random site is nearest.
 *
 * One definition, and out of it fall crystal grains, giraffe markings, cracked
 * mud, territory maps and the cell structure of almost every organic-looking
 * texture in computer graphics. Nothing here draws an outline — the outlines are
 * where the nearest and second-nearest sites are the same distance away, and the
 * renderer finds them for free while it is already measuring both.
 *
 * This is the one generator in the module that wants Palette::build() rather than
 * Palette::ramp(). Everywhere else colour stands for a continuous quantity and
 * categorical hues would interpolate through mud; here the cells are discrete
 * objects and the job is the opposite one — two cells sharing a border have to be
 * told apart, and a ramp would make neighbours nearly identical. The sites are
 * additionally coloured greedily, each one avoiding the colours of the sites
 * nearest it, because a palette of eight assigned at random still puts two
 * identical cells against each other about one border in eight.
 */
final class VoronoiGenerator extends BaseGenerator
{
    /**
     * How many already-coloured neighbours a site tries to differ from.
     *
     * A site in a random Voronoi diagram has six cell neighbours on average — the
     * Delaunay triangulation is planar, so the mean degree is exactly 6 — and
     * taking the six nearest sites is a cheap stand-in for computing that
     * triangulation properly. Going wider costs colours: with only a handful in
     * the palette, avoiding twelve neighbours means avoiding everything.
     */
    private const NEIGHBOURS = 6;

    public function key(): string
    {
        return 'patterns.voronoi';
    }

    public function name(): string
    {
        return 'Voronoi';
    }

    public function tagline(): string
    {
        return 'Cells from random sites, coloured so no two neighbours match.';
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
            ->int('sites', 'Cells', default: 90, min: 4, max: 220)
            ->enum('colouring', 'Colour cells by', [
                'cell' => 'Identity (no two neighbours alike)',
                'area' => 'Area',
            ], default: 'cell')
            ->float('edges', 'Border thickness', default: 1.6, min: 0.0, max: 8.0, step: 0.2, help: 'A border is where the nearest and second-nearest sites are equally far away. Zero draws none.')
            ->bool('dots', 'Mark the sites', help: 'The point each cell grew from.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'golden')
            ->int('colours', 'Palette size', default: 8, min: 3, max: 12);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // build(), not ramp() — the one place in this module where that is right.
        // See the class comment: these are discrete objects that must be told
        // apart, not samples of a continuous field.
        $palette = Palette::build($rng, $params->string('palette', 'golden'), $params->int('colours', 8));

        $width = $params->int('width');
        $height = $params->int('height');
        $count = $params->int('sites');

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            // Integer pixel coordinates: a site is a position on a raster and a
            // fractional one would move no boundary by a visible amount, while
            // costing three characters each in a spec that has to stay small.
            $sites[] = [
                $rng->intBetween(0, $width - 1),
                $rng->intBetween(0, $height - 1),
            ];
        }

        $sites = $this->colourGreedily($rng, $sites, $params->int('colours', 8));

        $spec = [
            'algorithm' => 'voronoi',
            'width' => $width,
            'height' => $height,
            'colouring' => $params->string('colouring', 'cell'),
            'edges' => $params->float('edges'),
            'dots' => $params->bool('dots'),
            'palette' => $palette,
            'sites' => $sites,
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%d cells · %s · %s',
                $count,
                $params->string('colouring', 'cell') === 'area' ? 'coloured by area' : 'coloured by identity',
                $params->float('edges') > 0 ? sprintf('%.1f px borders', $params->float('edges')) : 'no borders',
            ),
            meta: [
                'palette' => $palette,
                'sites' => $count,
                // The expected cell area is simply the canvas divided by the site
                // count; the interesting part is that the *distribution* of areas
                // around it is wide, because the sites are independent and clump.
                'mean_cell_area' => (int) round($width * $height / $count),
                'mean_neighbours' => 6,
                'note' => sprintf(
                    'Every pixel is painted the colour of whichever of the %d sites is nearest to it. The borders are drawn nowhere: they are the places where the nearest and second-nearest sites are the same distance away, which the renderer already knows because it measures both. Cell areas vary widely around their %s px average — the sites are independent, so they clump, and a clump of sites means a cluster of small cells.',
                    $count,
                    number_format((int) round($width * $height / $count)),
                ),
            ],
        );
    }

    /**
     * Give every site a colour none of its nearest neighbours is using.
     *
     * Greedy, in the order the sites were drawn, looking only backwards at sites
     * already coloured: a proper graph colouring would want the Delaunay
     * triangulation and a good deal more code for a picture that would look the
     * same. Where the palette is too small to avoid every neighbour the least-used
     * colour wins, which degrades gracefully instead of failing.
     *
     * Ties go to the Rng rather than to the lowest index. Taking the first
     * acceptable colour every time is the obvious spelling and quietly wrecks the
     * palette: a site with no coloured neighbours yet, and every site whose
     * neighbours happen to miss the early colours, all land on the same one, and
     * the first two hues end up covering half the canvas.
     *
     * @param  list<array{int, int}>  $sites
     * @return list<array{int, int, int}> x, y, palette index
     */
    private function colourGreedily(Rng $rng, array $sites, int $colours): array
    {
        $out = [];

        foreach ($sites as [$x, $y]) {
            $distances = [];
            foreach ($out as $j => [$ox, $oy]) {
                $distances[$j] = ($ox - $x) ** 2 + ($oy - $y) ** 2;
            }

            asort($distances);
            $penalty = array_fill(0, $colours, 0);

            // Nearer neighbours matter more: sharing a colour with the site right
            // next door is a visible seam, sharing with the sixth nearest usually
            // is not a shared border at all.
            $rank = 0;
            foreach (array_slice(array_keys($distances), 0, self::NEIGHBOURS) as $j) {
                $penalty[$out[$j][2]] += self::NEIGHBOURS - $rank++;
            }

            $lowest = min($penalty);
            $candidates = array_keys($penalty, $lowest, true);

            $out[] = [$x, $y, $rng->pick($candidates)];
        }

        return $out;
    }
}
