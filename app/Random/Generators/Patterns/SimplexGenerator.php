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
 * Gradient noise on a triangular lattice instead of a square one.
 *
 * Perlin's cell is a square, and a square has axes. Every feature in a Perlin
 * field is therefore pulled a little towards 0°, 45° and 90°, which is why a
 * large Perlin canvas always reads as faintly ruled. A simplex lattice tiles the
 * plane with equilateral triangles and has no preferred direction at all, so the
 * same fBm settings produce a field with the same statistics and none of the
 * grain.
 *
 * The difference is real but small enough to be argued about, so this generator
 * ships a **comparison mode**: the same seed, the same octaves, the same offsets,
 * OpenSimplex2 on the left half and Perlin on the right, normalised together so
 * neither half gets flattered. That is the honest way to make the claim, and it
 * is a better demonstration than any amount of prose about isotropy.
 *
 * OpenSimplex2 rather than Ken Perlin's own simplex, following docs/07 §1.3: it
 * sidesteps the simplex patent lineage entirely, and its quartic kernel over
 * r² = 2/3 is visibly smoother than classic simplex's cubic over 1/2.
 */
final class SimplexGenerator extends BaseGenerator
{
    public function key(): string
    {
        return 'patterns.simplex';
    }

    public function name(): string
    {
        return 'Simplex Noise';
    }

    public function tagline(): string
    {
        return 'Fewer directional artefacts, cheaper in higher dimensions.';
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
            ->float('scale', 'Zoom', default: 3.0, min: 0.5, max: 16.0, step: 0.1, help: 'How many lattice cells fit across the canvas.')
            ->int('octaves', 'Detail (octaves)', default: 5, min: 1, max: 9, help: 'One octave is plain OpenSimplex2. Each one adds finer detail at half the strength.')
            ->float('persistence', 'Roughness', default: 0.5, min: 0.2, max: 0.85, step: 0.05)
            ->float('lacunarity', 'Frequency step', default: 2.0, min: 1.4, max: 3.2, step: 0.1)
            ->enum('variant', 'Shape', [
                'fbm' => 'Clouds',
                'turbulence' => 'Smoke',
                'ridged' => 'Ridges',
                'billow' => 'Billows',
            ], default: 'fbm')
            ->float('warp', 'Domain warp', default: 0.0, min: 0.0, max: 1.2, step: 0.05, help: 'Folds the noise through a copy of itself.')
            ->bool('compare', 'Compare with Perlin', help: 'Splits the canvas: OpenSimplex2 left, Perlin right, same seed and same settings. Perlin\'s square lattice shows as a faint grain along the axes.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'viridis')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12)
            ->bool('contours', 'Banding', help: 'Quantise into flat bands instead of a smooth gradient.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // ramp(), not build(): a noise field is a height map, and categorical
        // colours interpolate through mud. docs/07 §9.
        $palette = Palette::ramp($rng, $params->string('palette', 'viridis'), $params->int('colours', 7));

        $spec = [
            'algorithm' => 'simplex',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'scale' => $params->float('scale'),
            'octaves' => $params->int('octaves'),
            'persistence' => $params->float('persistence'),
            'lacunarity' => $params->float('lacunarity'),
            'variant' => $params->string('variant', 'fbm'),
            'warp' => $params->float('warp'),
            'compare' => $params->bool('compare'),
            'contours' => $params->bool('contours'),
            'palette' => $palette,
            // One word decides which of the 24 gradients every lattice point
            // holds. A permutation table would have been the Perlin-shaped
            // answer, and would have re-imposed the 256-cell repeat that the
            // triangular lattice otherwise has no reason to carry.
            'seed' => $rng->uint32(),
            'offset' => [$rng->float() * 1024, $rng->float() * 1024],
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $palette),
            meta: [
                'palette' => $palette,
                'lattice' => 'triangular (OpenSimplex2)',
                // The headline number: 2ⁿ corners per sample for Perlin against
                // n+1 for simplex. In 2D that is 4 against 3 and barely matters;
                // it is why nobody uses Perlin in four dimensions.
                'corners_per_sample' => 3,
                'perlin_corners_per_sample' => 4,
                'gradient_directions' => 24,
                'note' => 'A square lattice has axes, so Perlin features lean towards 0°, 45° and 90°. An equilateral lattice has no preferred direction, and the 24 gradient vectors are spaced every 15° rather than Perlin\'s eight. Turn on Compare to see both halves of the same field, normalised together.',
            ],
        );
    }

    private function describe(Params $params, array $palette): string
    {
        $variant = ['fbm' => 'Clouds', 'turbulence' => 'Smoke', 'ridged' => 'Ridges', 'billow' => 'Billows'][$params->string('variant', 'fbm')] ?? 'Clouds';

        return sprintf(
            '%s · %d octaves · zoom %.1f%s%s · %d colours',
            $variant,
            $params->int('octaves'),
            $params->float('scale'),
            $params->float('warp') > 0 ? sprintf(' · warp %.2f', $params->float('warp')) : '',
            $params->bool('compare') ? ' · against Perlin' : '',
            count($palette),
        );
    }
}
