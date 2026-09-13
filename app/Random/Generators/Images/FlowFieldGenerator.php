<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Particles released into a noise field and left to follow it.
 *
 *     θ(x, y) = fBm(x·s, y·s) · 2π · turns
 *     p      ← p + (cos θ, sin θ) · step
 *
 * That is the whole algorithm. Everything that makes the output worth looking at
 * is in the accumulation: a thousand near-transparent trails laid over each other
 * additively, so the picture is built out of where paths *agree* rather than out
 * of any one path. Turn the alpha up and it collapses into spaghetti; the low
 * value is the design.
 *
 * The particle seeds are drawn in the browser rather than shipped in the spec.
 * A thousand start positions is 16 KB of JSON, and the render key already
 * guarantees the browser draws the same thousand the server would have — which
 * is the entire point of having a client-side twin of the Rng.
 */
final class FlowFieldGenerator extends ImageGenerator
{
    public function key(): string
    {
        return 'images.flowfield';
    }

    public function name(): string
    {
        return 'Flow Field';
    }

    public function tagline(): string
    {
        return 'Thousands of particles advected through fractal noise.';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 1200, 750)
            ->int('particles', 'Particles', default: 1500, min: 100, max: 2500, help: 'Each one traces a single trail and is never reused.')
            ->int('trail', 'Trail length', default: 190, min: 10, max: 420, help: 'Steps before a particle runs out. Long trails cross the whole canvas; short ones read as brushwork.')
            ->float('step', 'Step length', default: 1.6, min: 0.4, max: 6.0, step: 0.1, help: 'Pixels advanced per step. Small steps hug the field, large ones cut across it.')
            ->float('scale', 'Field zoom', default: 2.2, min: 0.4, max: 9.0, step: 0.1, help: 'How many noise cells fit across the canvas.')
            ->int('octaves', 'Detail (octaves)', default: 4, min: 1, max: 7)
            ->float('turns', 'Angular range', default: 1.0, min: 0.2, max: 3.0, step: 0.05, help: 'How many full turns the noise maps onto. Below 1 the flow stays combed in one direction; above 2 it knots.')
            ->float('alpha', 'Line alpha', default: 0.2, min: 0.01, max: 0.8, step: 0.005, help: 'Per-segment opacity. The picture is the overlap, so this wants to stay low.')
            ->float('line', 'Line width', default: 1.1, min: 0.4, max: 4.0, step: 0.1)
            ->bool('glow', 'Additive blending', default: true, help: 'Trails add their light together instead of painting over each other. On a dark ground this is the whole look.')
            ->enum('background', 'Ground', self::GROUNDS, default: 'ink')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'golden')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.018, min: 0.0, max: 0.08, step: 0.002, help: 'Per-pixel noise over the finished render. A little of it is the difference between generative and computer-made.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('colours', 6);

        /*
         * ramp(), not build(): a trail brightens as it travels, so the renderer
         * interpolates between neighbouring stops every step. Categorical colours
         * would put 200° of hue between two adjacent stops and every intermediate
         * value would land in the mud between them.
         *
         * Two extra stops are drawn and the two darkest dropped. A ramp starts
         * at L≈0.16 and climbs slowly; an ink ground sits at L≈0.07. A trail
         * that close to the background is a particle nobody can see, and a
         * sixth of the palette spent on invisible is a sixth of the particles
         * wasted.
         */
        $palette = array_slice(Palette::ramp($rng, $params->string('palette', 'golden'), $count + 2), 2);

        $background = $this->ground($params->string('background', 'ink'), $palette);

        $spec = [
            'algorithm' => 'flowfield',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'particles' => $params->int('particles'),
            'trail' => $params->int('trail'),
            'step' => $params->float('step'),
            'scale' => $params->float('scale'),
            'octaves' => $params->int('octaves'),
            // fBm parameters the field does not expose as controls. The flow field
            // wants a smooth, well-behaved field to follow, and the interesting
            // range of these two is much narrower here than it is for a texture.
            'persistence' => 0.5,
            'lacunarity' => 2.0,
            'variant' => 'fbm',
            'turns' => $params->float('turns'),
            'alpha' => $params->float('alpha'),
            'line' => $params->float('line'),
            'glow' => $params->bool('glow'),
            // How far along the ramp a trail travels over its own lifetime. Fixed
            // rather than exposed: it is the difference between a trail and a
            // coloured line, and there is no value here anyone would want to set
            // to zero.
            'drift' => 0.38,
            'background' => $background,
            'palette' => $palette,
            // Drawn from the seed rather than the browser, so that two canvases
            // with identical parameters still land on different parts of the
            // field. The sliders shape the flow; the seed decides where you
            // stand in it.
            'offset' => [$rng->float() * 1024, $rng->float() * 1024],
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params),
            meta: [
                'palette' => $palette,
                'background' => $background,
                // The real size of the drawing job, which is what makes the
                // browser's render time an order of magnitude above the server's.
                'segments' => $params->int('particles') * $params->int('trail'),
                'field_evaluations' => $params->int('particles') * $params->int('trail') * $params->int('octaves'),
            ],
        );
    }

    private function describe(Params $params): string
    {
        return sprintf(
            '%s particles · %d-step trails · step %.1fpx · zoom %.1f · %d octaves · alpha %.3f',
            number_format($params->int('particles')),
            $params->int('trail'),
            $params->float('step'),
            $params->float('scale'),
            $params->int('octaves'),
            $params->float('alpha'),
        );
    }
}
