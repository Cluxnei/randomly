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
 * Cellular noise: the distance from every pixel to the feature points scattered
 * around it.
 *
 * Where Perlin interpolates a lattice, Worley measures one, and the whole
 * personality of the output comes from which distance you keep. F₁ alone gives
 * bubbles, F₂ gives their shadows, and F₂−F₁ collapses to zero exactly on the
 * boundary between two cells — which is why it draws the cracked-mud look that
 * every stone and reptile-skin texture is built from.
 *
 * The distance *metric* is the other half, and it is the reason this generator
 * earns a slider rather than a preset: the same feature points read as soap
 * bubbles under Euclidean, as diamonds under Manhattan and as squares under
 * Chebyshev. Minkowski-p sweeps continuously between all three (p=1, p=2,
 * p→∞), so the demo is one drag long.
 */
final class WorleyGenerator extends BaseGenerator
{
    /**
     * F and the metric labels live here rather than being rebuilt in describe(),
     * so the panel and the caption can never drift apart.
     */
    private const FEATURES = [
        'f1' => 'Cells',
        'f2' => 'Second nearest',
        'f2f1' => 'Cracks (F₂−F₁)',
        'f1f2' => 'Veins (F₁·F₂)',
    ];

    private const METRICS = [
        'euclidean' => 'Euclidean (bubbles)',
        'manhattan' => 'Manhattan (diamonds)',
        'chebyshev' => 'Chebyshev (squares)',
        'minkowski' => 'Minkowski-p (a slider between them)',
    ];

    public function key(): string
    {
        return 'patterns.worley';
    }

    public function name(): string
    {
        return 'Worley Cells';
    }

    public function tagline(): string
    {
        return 'Distance to the nth-nearest feature point.';
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
            ->float('density', 'Cells across', default: 9.0, min: 2.0, max: 48.0, step: 0.5, help: 'Feature points are scattered one per grid cell, so this is both the cell count and the point count.')
            ->enum('feature', 'Distance kept', self::FEATURES, default: 'f2f1')
            ->enum('metric', 'Distance metric', self::METRICS, default: 'euclidean')
            ->float('p', 'Minkowski p', default: 3.0, min: 0.4, max: 8.0, step: 0.1, help: 'Only used by the Minkowski metric. p=1 is Manhattan, p=2 is Euclidean, and large p approaches Chebyshev.')
            ->float('jitter', 'Scatter', default: 1.0, min: 0.0, max: 1.0, step: 0.05, help: 'How far a point may stray from its cell centre. At 0 the grid is perfectly regular and you get a tiling.')
            ->bool('invert', 'Invert')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'viridis')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12)
            ->bool('contours', 'Banding', help: 'Quantise into flat bands instead of a smooth gradient.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // ramp(), not build(): a distance field is a height map, and every pixel
        // between two stops gets an interpolated colour.
        $palette = Palette::ramp($rng, $params->string('palette', 'viridis'), $params->int('colours', 7));

        // The feature points are hashed from their cell coordinates in the browser
        // rather than drawn one at a time from the stream: a 48-cell grid over a
        // warped domain would want thousands of draws, and the hash is position
        // independent, so panning the field never renumbers a point. This single
        // uint32 is what makes two canvases with identical sliders differ.
        $seed = $rng->uint32();
        $offset = [$rng->float() * 512, $rng->float() * 512];

        $spec = [
            'algorithm' => 'worley',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'density' => $params->float('density'),
            'feature' => $params->string('feature', 'f2f1'),
            'metric' => $params->string('metric', 'euclidean'),
            'p' => $params->float('p'),
            'jitter' => $params->float('jitter'),
            'invert' => $params->bool('invert'),
            'contours' => $params->bool('contours'),
            'palette' => $palette,
            'seed' => $seed,
            'offset' => $offset,
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $palette),
            meta: [
                'palette' => $palette,
                // Roughly how many feature points the visible canvas contains —
                // the honest measure of "how busy is this", since density is
                // counted across the longer edge only.
                'feature_points' => $this->featurePoints($params),
                'effective_p' => $this->effectiveP($params),
            ],
        );
    }

    private function describe(Params $params, array $palette): string
    {
        return sprintf(
            '%s · %s · %.1f cells across · %d colours',
            self::FEATURES[$params->string('feature', 'f2f1')] ?? 'Cells',
            $params->string('metric') === 'minkowski'
                ? sprintf('Minkowski p=%.1f', $params->float('p'))
                : ucfirst($params->string('metric', 'euclidean')),
            $params->float('density'),
            count($palette),
        );
    }

    private function featurePoints(Params $params): int
    {
        $step = $params->float('density') / max($params->int('width'), $params->int('height'));

        return (int) ceil($params->int('width') * $step) * (int) ceil($params->int('height') * $step);
    }

    /**
     * The exponent the renderer actually uses, with the three named metrics
     * expressed in the same language — INF for Chebyshev, since that is what the
     * max() shortcut is a limit of.
     */
    private function effectiveP(Params $params): float|string
    {
        return match ($params->string('metric', 'euclidean')) {
            'manhattan' => 1.0,
            'chebyshev' => 'inf',
            'minkowski' => round($params->float('p'), 2),
            default => 2.0,
        };
    }
}
