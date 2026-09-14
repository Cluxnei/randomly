<?php

declare(strict_types=1);

namespace App\Random\Generators\Patterns;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Palette\Oklch;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * A crystal grown one sticky particle at a time.
 *
 * Release a particle far from the cluster, let it wander until it touches, freeze
 * it where it landed, repeat. Nothing in that rule mentions branches, and yet the
 * result is the same dendrite that copper deposits, frost, lightning, coral and a
 * starved bacterial colony all arrive at independently — with a fractal dimension
 * of about 1.71 in the plane that nobody chose.
 *
 * The branching comes out of a screening effect. A wandering particle is far more
 * likely to meet a tip than the fjord behind it, because reaching the fjord means
 * walking past the tips on either side without touching either one. So any
 * protrusion captures more than its share of arrivals, grows faster, and screens
 * its neighbours harder. The picture is a record of that compounding advantage,
 * which is why colouring by arrival order rather than by position is the right
 * choice: the palette then shows the order the race was run in.
 *
 * Naive DLA is famously slow. The renderer's trick is that a particle with d
 * cells of clear space around it takes one step of d rather than d steps of one —
 * it has to end up somewhere on a circle of radius d, and a uniform angle is
 * exactly that distribution. The statistics are unchanged and the render is two
 * orders of magnitude faster, which is the difference between a slider and a
 * progress bar.
 */
final class DlaGenerator extends BaseGenerator
{
    private const SEEDS = [
        'point' => 'A single seed in the middle',
        'line' => 'A seeded floor (a forest of spires)',
    ];

    /**
     * The most cells the lattice may hold.
     *
     * The lattice is a cell per particle at best, so this also bounds memory; the
     * real reason for the ceiling is the walking, which grows with the launch
     * circle and therefore with the grid. 260k cells is a 640×400 lattice, past
     * the point where an individual particle is visible on a 900px canvas.
     */
    private const MAX_CELLS = 260000;

    public function key(): string
    {
        return 'patterns.dla';
    }

    public function name(): string
    {
        return 'Diffusion-Limited Aggregation';
    }

    public function tagline(): string
    {
        return 'Dendritic crystals, grown one sticky particle at a time.';
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
            // Nearly square by default: a cluster grown from a point is round, and
            // the finished cluster is fitted to the frame, so a wide canvas would
            // spend a third of itself on black either side of it.
            ->int('width', 'Width', default: 880, min: 200, max: 2048)
            ->int('height', 'Height', default: 800, min: 200, max: 2048)
            ->enum('seed_shape', 'Grow from', self::SEEDS, default: 'point')
            ->int('particles', 'Particles', default: 7000, min: 200, max: 40000, help: 'Every one of them is walked until it touches, which is the entire cost of the render. The run ends at this count or when the cluster reaches the edge of its lattice, whichever comes first — and the finished cluster is then fitted to the frame, so fewer particles means a chunkier crystal rather than a smaller one.')
            ->int('cell', 'Lattice', default: 2, min: 1, max: 10, help: 'The lattice the crystal grows on, as a divisor of the canvas. A finer lattice grows a cluster with finer branches, and costs a great deal more walking: halving this roughly triples the render.')
            ->float('stickiness', 'Stickiness', default: 1.0, min: 0.02, max: 1.0, step: 0.02, help: 'The chance a particle freezes when it touches. Below 1 it can work its way into the crevices before committing, which thickens the branches and pushes the fractal dimension towards 2 — frost at one end of this slider, sponge at the other.')
            ->float('glow', 'Glow', default: 0.05, min: 0.0, max: 0.3, step: 0.005, help: 'A wide, faint halo under every particle, added rather than painted. Haloes overlap where the cluster is dense, so the trunk lights up and a lone tip does not.')
            ->enum('background', 'Ground', ['ink' => 'Ink', 'paper' => 'Paper', 'palette' => 'From the palette'], default: 'ink')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'viridis')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.014, min: 0.0, max: 0.08, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        /*
         * ramp(), not build(). Arrival order is a continuous quantity — the
         * fourteen-thousandth particle is barely distinguishable from the
         * thirteen-thousandth — and the renderer interpolates between stops for
         * every cell. Categorical colours would put 200° of hue between two
         * particles that arrived a moment apart.
         */
        $palette = Palette::ramp($rng, $params->string('palette', 'viridis'), $params->int('colours', 7));

        $background = match ($params->string('background', 'ink')) {
            'paper' => Oklch::toHex(0.965, 0.004, 90),
            'palette' => $palette[0],
            default => Oklch::toHex(0.07, 0.012, 265),
        };

        [$cell, $cols, $rows] = $this->grid($params);

        // A cluster cannot contain more particles than the lattice has cells, and
        // asking for more would spend the whole step budget walking particles
        // that have nowhere left to stick.
        $particles = min($params->int('particles'), intdiv($cols * $rows, 2));

        $spec = [
            'algorithm' => 'dla',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'cell' => $cell,
            'cols' => $cols,
            'rows' => $rows,
            'particles' => $particles,
            'seed_shape' => $params->string('seed_shape', 'point'),
            'stickiness' => $params->float('stickiness'),
            'glow' => $params->float('glow'),
            'background' => $background,
            'palette' => $palette,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
            // Fourteen thousand walks of a few hundred steps each, two draws per
            // step, all expanded from this one word in the browser. The render
            // stream is 8 KB; see patterns/prng.js.
            'seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%s particles · %d×%d lattice · %s · stickiness %.2f',
                number_format($particles),
                $cols,
                $rows,
                $params->string('seed_shape', 'point') === 'line' ? 'from a floor' : 'from a point',
                $params->float('stickiness'),
            ),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'particles' => $particles,
                'lattice' => $cols.'×'.$rows,
                // The measured dimension of planar DLA, quoted rather than
                // computed: measuring it honestly needs a box-counting pass over
                // a cluster an order of magnitude larger than this one.
                'fractal_dimension' => '≈1.71 (planar DLA, measured)',
                'note' => $this->note($params),
            ],
        );
    }

    /**
     * Cell size grown until the lattice fits the budget.
     *
     * The same treatment patterns.maze gives its grid, and for a sharper reason
     * here: the cost of a particle is the distance it has to walk, which scales
     * with the launch circle, which scales with the lattice. Halving the cell
     * size on a full-size canvas roughly octuples the render.
     *
     * @return array{int, int, int} cell, cols, rows
     */
    private function grid(Params $params): array
    {
        $cell = max(1, $params->int('cell', 3));

        while (true) {
            $cols = (int) ceil($params->int('width') / $cell);
            $rows = (int) ceil($params->int('height') / $cell);

            if ($cols * $rows <= self::MAX_CELLS) {
                return [$cell, $cols, $rows];
            }

            $cell++;
        }
    }

    private function note(Params $params): string
    {
        $shape = $params->string('seed_shape', 'point') === 'line'
            ? 'Grown up from a seeded floor, so the branches are a forest of spires racing each other for the particles falling from above — the tall ones screen the short ones and the gap widens.'
            : 'Grown outward from one seed cell, so the cluster is a star whose arms competed for every arrival.';

        $sticky = $params->float('stickiness') < 0.99
            ? sprintf(' At a stickiness of %.2f a particle usually declines to freeze on first contact, so it can reach into a crevice before committing and the branches come out thicker.', $params->float('stickiness'))
            : ' At full stickiness a particle freezes at the first thing it touches, which is almost always a tip — hence the thinnest, most dendritic form the rule produces.';

        return $shape.$sticky.' Colour runs along the palette in arrival order, so the ramp is the history of the growth rather than a restatement of its shape.';
    }
}
