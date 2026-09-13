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
 * Gray–Scott reaction–diffusion: two chemicals, one of which eats the other.
 *
 *   ∂u/∂t = Dᵤ∇²u − uv² + F(1 − u)
 *   ∂v/∂t = Dᵥ∇²v + uv² − (F + k)v
 *
 * Integrated with explicit Euler at Δt = 1 and the 9-point Laplacian, which is
 * the cheapest scheme that does not show the grid in the result — a 5-point
 * stencil leaves visible axis-aligned bias in the spots.
 *
 * Everything that makes this generator worth having is in the (F, k) plane. The
 * same equations, the same code, the same seed produce dividing cells, coral,
 * drifting solitons or a labyrinth depending on two numbers in the third decimal
 * place, and almost all of the plane is a field that simply dies. The named
 * presets exist so the first thing a visitor sees is one of the live ones; the
 * sliders exist so the second thing they do is find out how narrow the live
 * region is.
 */
final class ReactionGenerator extends BaseGenerator
{
    /**
     * The (F, k) pairs from docs/07 §5. These are the coordinates worth knowing;
     * everything between them is reachable from the two sliders.
     *
     * @var array<string, array{float, float, string}> preset => [F, k, label]
     */
    private const PRESETS = [
        'mitosis' => [0.035, 0.065, 'Mitosis — dividing cells'],
        'coral' => [0.055, 0.062, 'Coral growth'],
        'solitons' => [0.030, 0.062, 'Solitons'],
        // docs/07 §5 lists k = 0.050 for the labyrinth, and that pair is dead in
        // this integration: the disturbance decays to a near-uniform field and
        // stays there through thirty thousand steps, whatever it is seeded with.
        // Sweeping k at F = 0.025 puts the stripe regime at 0.055, which is where
        // the published Pearson diagram puts it too, so the doc's 0.050 looks like
        // a transcription slip rather than a difference of method. Shipping the
        // documented number would have shipped a preset that renders mud.
        'labyrinth' => [0.025, 0.055, 'Labyrinth'],
        'spots' => [0.014, 0.054, 'Pulsating spots'],
        'custom' => [0.0, 0.0, 'Custom (use the sliders)'],
    ];

    /**
     * The ceiling on cell-updates per render, which is what actually costs time:
     * grid² × steps. A cell update is eighteen typed-array loads and a dozen
     * multiplies, which measures at roughly 7 ns, so this budget is a shade over
     * a second.
     *
     * Capping the product rather than the two sliders separately is the only
     * version that works — 288² at 12,000 steps is eight times the budget, and a
     * slider drag that locks the tab for ten seconds is worse than a slider that
     * stops early and says so in the receipt.
     */
    private const UPDATE_BUDGET = 140_000_000;

    public function key(): string
    {
        return 'patterns.reaction';
    }

    public function name(): string
    {
        return 'Reaction–Diffusion';
    }

    public function tagline(): string
    {
        return 'Gray–Scott, run until the pattern stops moving.';
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
            ->enum('preset', 'Pattern', array_map(fn (array $p): string => $p[2], self::PRESETS), default: 'coral')
            ->float('feed', 'Feed rate F', default: 0.035, min: 0.005, max: 0.09, step: 0.001, help: 'Only used when the preset is Custom. Most of this range is a field that dies within a few hundred steps — that is the honest shape of the parameter space.')
            ->float('kill', 'Kill rate k', default: 0.065, min: 0.03, max: 0.075, step: 0.001, help: 'Only used when the preset is Custom.')
            ->int('grid', 'Grid', default: 192, min: 96, max: 288, help: 'The simulation runs on a square grid and is sampled up to the canvas, so this is detail, not size.')
            ->int('steps', 'Steps', default: 3200, min: 200, max: 12000, help: 'How long the reaction runs. Capped against the grid size so a slider drag cannot lock the tab.')
            /*
             * Δt = 1 and diffusion on a unit lattice, so these are the lattice
             * rates rather than the physical ones: docs/07 quotes Pearson's
             * Dᵤ=0.16, Dᵥ=0.08, which are measured against his grid spacing and
             * amount to roughly a sixth of a cell per step. Patterns do form at
             * that rate — they just need sixty thousand steps to do it, which is
             * a tab locked for a minute. Keeping the 2:1 ratio and moving both up
             * to Karl Sims' 1.0/0.5 is the same simulation with the clock sped up.
             *
             * 1.2 is the ceiling because the 9-point Laplacian's most negative
             * Fourier eigenvalue is −1.6, so explicit Euler diverges above
             * D·Δt = 2/1.6 = 1.25 and the field turns to NaN. Bounding the slider
             * is cheaper and more honest than clamping every cell of every step to
             * hide the divergence.
             */
            ->float('du', 'Diffusion of u', default: 1.0, min: 0.2, max: 1.2, step: 0.05)
            ->float('dv', 'Diffusion of v', default: 0.5, min: 0.1, max: 0.7, step: 0.05)
            ->int('seeds', 'Seed patches', default: 14, min: 1, max: 60, help: 'The initial disturbance. Everything on screen grows out of these.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'magma')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12)
            ->bool('contours', 'Banding')
            ->bool('invert', 'Invert');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        [$feed, $kill] = $this->rates($params);

        // ramp(): v is a concentration, so this is a continuous field and every
        // pixel between two stops is an interpolated colour.
        $palette = Palette::ramp($rng, $params->string('palette', 'magma'), $params->int('colours', 6));

        $grid = $params->int('grid');
        $steps = $this->steps($params);

        /*
         * The initial disturbance, drawn here rather than in the browser.
         *
         * This is the one place the entropy actually enters the simulation —
         * after step zero the equations are deterministic, so where these patches
         * land is the entire difference between two runs of the same preset.
         * Coordinates are normalised so the grid slider can move without
         * reseeding the pattern.
         */
        $seeds = [];

        for ($i = 0; $i < $params->int('seeds'); $i++) {
            $seeds[] = [
                round($rng->float(), 5),
                round($rng->float(), 5),
                round(0.015 + $rng->float() * 0.045, 5),
            ];
        }

        $spec = [
            'algorithm' => 'reaction',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'grid' => $grid,
            'steps' => $steps,
            'feed' => $feed,
            'kill' => $kill,
            'du' => $params->float('du'),
            'dv' => $params->float('dv'),
            'seeds' => $seeds,
            'contours' => $params->bool('contours'),
            'invert' => $params->bool('invert'),
            'palette' => $palette,
            'noise_seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $feed, $kill, $steps),
            meta: [
                'palette' => $palette,
                'feed' => $feed,
                'kill' => $kill,
                'steps_requested' => $params->int('steps'),
                'steps_run' => $steps,
                'cell_updates' => $grid * $grid * $steps,
            ],
        );
    }

    /** @return array{float, float} */
    private function rates(Params $params): array
    {
        $preset = $params->string('preset', 'coral');

        if ($preset === 'custom' || ! isset(self::PRESETS[$preset])) {
            return [$params->float('feed'), $params->float('kill')];
        }

        return [self::PRESETS[$preset][0], self::PRESETS[$preset][1]];
    }

    private function steps(Params $params): int
    {
        $grid = max(1, $params->int('grid'));

        return max(50, min(
            $params->int('steps'),
            intdiv(self::UPDATE_BUDGET, $grid * $grid),
        ));
    }

    private function describe(Params $params, float $feed, float $kill, int $steps): string
    {
        $preset = $params->string('preset', 'coral');
        $label = $preset === 'custom' ? 'Custom' : explode(' —', self::PRESETS[$preset][2])[0];

        return sprintf(
            '%s · F=%.3f k=%.3f · %d² grid · %d steps',
            $label,
            $feed,
            $kill,
            $params->int('grid'),
            $steps,
        );
    }
}
