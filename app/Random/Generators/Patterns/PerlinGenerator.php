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
 * Gradient noise, stacked into fractal detail and optionally folded back through
 * itself.
 *
 * The first canvas generator, and the reference for the rest: it emits a *spec*
 * rather than pixels. The browser re-derives the identical byte stream from the
 * render key and does the drawing, which keeps the response at a few hundred
 * bytes instead of a few megabytes and makes dragging a slider instant — the
 * parameters change, the seed does not, and nothing goes back to the server.
 *
 * Three of the roadmap's separate entries live here as parameters rather than as
 * generators. Octaves at 1 is plain Perlin and above 1 is fBm; warp strength at 0
 * is off. They are the same code path with a slider, and splitting them into
 * three menu items would have been a catalogue padded for the sake of a number.
 */
final class PerlinGenerator extends BaseGenerator
{
    public function key(): string
    {
        return 'patterns.perlin';
    }

    public function name(): string
    {
        return 'Perlin Noise';
    }

    public function tagline(): string
    {
        return 'Gradient noise, stacked into fractals and folded through itself.';
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
            ->float('scale', 'Zoom', default: 3.0, min: 0.5, max: 16.0, step: 0.1, help: 'How many noise cells fit across the canvas.')
            ->int('octaves', 'Detail (octaves)', default: 5, min: 1, max: 9, help: 'One octave is plain Perlin. Each one adds finer detail at half the strength.')
            ->float('persistence', 'Roughness', default: 0.5, min: 0.2, max: 0.85, step: 0.05, help: 'How much each finer octave contributes.')
            ->float('lacunarity', 'Frequency step', default: 2.0, min: 1.4, max: 3.2, step: 0.1)
            ->enum('variant', 'Shape', [
                'fbm' => 'Clouds',
                'turbulence' => 'Smoke',
                'ridged' => 'Ridges',
                'billow' => 'Billows',
            ], default: 'fbm')
            ->float('warp', 'Domain warp', default: 0.0, min: 0.0, max: 1.2, step: 0.05, help: 'Folds the noise through a copy of itself. The single most dramatic control here.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'golden')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12)
            ->bool('contours', 'Banding', help: 'Quantise into flat bands instead of a smooth gradient.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // ramp(), not build(): this colours a continuous field, so the stops have
        // to interpolate cleanly into one another.
        $palette = Palette::ramp($rng, $params->string('palette', 'golden'), $params->int('colours', 6));

        // Offsets drawn here rather than in the browser so that two canvases with
        // the same parameters still differ — the seed moves the field, the sliders
        // shape it.
        $offset = [$rng->float() * 1024, $rng->float() * 1024];

        $spec = [
            'algorithm' => 'perlin',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'scale' => $params->float('scale'),
            'octaves' => $params->int('octaves'),
            'persistence' => $params->float('persistence'),
            'lacunarity' => $params->float('lacunarity'),
            'variant' => $params->string('variant', 'fbm'),
            'warp' => $params->float('warp'),
            'contours' => $params->bool('contours'),
            'palette' => $palette,
            'offset' => $offset,
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $palette),
            meta: [
                'palette' => $palette,
                // Total amplitude of the octave stack, which is what the renderer
                // divides by to normalise back into [0,1].
                'amplitude_sum' => round($this->amplitudeSum($params), 4),
                'effective_frequency' => round($params->float('scale') * ($params->float('lacunarity') ** ($params->int('octaves') - 1)), 2),
            ],
        );
    }

    private function describe(Params $params, array $palette): string
    {
        $variant = ['fbm' => 'Clouds', 'turbulence' => 'Smoke', 'ridged' => 'Ridges', 'billow' => 'Billows'][$params->string('variant', 'fbm')] ?? 'Clouds';

        return sprintf(
            '%s · %d octaves · zoom %.1f%s · %d colours',
            $variant,
            $params->int('octaves'),
            $params->float('scale'),
            $params->float('warp') > 0 ? sprintf(' · warp %.2f', $params->float('warp')) : '',
            count($palette),
        );
    }

    private function amplitudeSum(Params $params): float
    {
        $sum = 0.0;
        $amplitude = 1.0;

        for ($i = 0; $i < $params->int('octaves'); $i++) {
            $sum += $amplitude;
            $amplitude *= $params->float('persistence');
        }

        return $sum;
    }
}
