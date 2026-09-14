<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Circle packing by dart throwing.
 *
 * Pick a point, grow a circle there until it touches something, keep it if it
 * grew large enough to be worth drawing, repeat a few tens of thousands of times.
 * There is no optimisation step and no relaxation: the structure comes entirely
 * out of the order the darts landed in. The first few circles are large because
 * the canvas is empty, and every later one has to take whatever gap is left.
 *
 * That is also why the size distribution is the most interesting thing to colour
 * by. A pack coloured by radius shows two populations — the early arrivals and
 * the crumbs that filled in behind them — and the boundary between them is not
 * something anybody drew.
 *
 * **The circles are allowed off the edge.** A pack that respects the frame draws
 * a rectangle in negative space and reads as a diagram of itself. Letting the
 * circles bleed makes the image a crop of something larger, which is the
 * difference between a composition and a screensaver — the same lesson
 * images.blob had to be rebuilt to learn (docs/08 §2.3).
 */
final class CirclesGenerator extends ImageGenerator
{
    private const STYLES = [
        'filled' => 'Filled',
        'rings' => 'Outlined',
        'nested' => 'Nested rings',
        'mixed' => 'Mixed fill and outline',
    ];

    private const COLOURINGS = [
        'radius' => 'By size',
        'field' => 'By a noise field',
        'order' => 'By arrival order',
    ];

    public function key(): string
    {
        return 'images.circles';
    }

    public function name(): string
    {
        return 'Circle Packing';
    }

    public function tagline(): string
    {
        return 'Grow until collision, keep what fits.';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 1100, 760)
            ->int('attempts', 'Darts thrown', default: 26000, min: 500, max: 90000, help: 'Candidate points tried. Most of the late ones land inside a circle that already exists and are thrown away; the ones that survive are what fills the gaps.')
            ->float('min_radius', 'Smallest circle', default: 3.0, min: 1.0, max: 40.0, step: 0.5, help: 'Candidates that cannot grow this large are discarded. Raise it and the gaps between the big circles stay open.')
            ->float('max_radius', 'Largest circle', default: 95.0, min: 8.0, max: 320.0, step: 1.0)
            ->float('gap', 'Gap', default: 2.5, min: 0.0, max: 24.0, step: 0.5, help: 'Clear space kept between circles. At zero they touch exactly, which is the true packing and reads as denser than it is.')
            ->enum('style', 'Draw as', self::STYLES, default: 'mixed')
            ->float('weight', 'Stroke weight', default: 0.14, min: 0.03, max: 0.5, step: 0.01, help: 'As a fraction of each circle\'s own radius, so a large circle is drawn more boldly than a small one rather than the same.')
            ->enum('colouring', 'Colour by', self::COLOURINGS, default: 'field')
            ->enum('background', 'Ground', self::GROUNDS, default: 'ink')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'golden')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.014, min: 0.0, max: 0.08, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('colours', 6);

        /*
         * ramp(), not build(). Every colouring here is a continuous quantity —
         * radius, a noise field, arrival order — and the renderer samples between
         * stops for each circle. The two darkest stops are dropped for the same
         * reason flowfield drops them: on an ink ground they are circles nobody
         * can see.
         */
        $palette = array_slice(Palette::ramp($rng, $params->string('palette', 'golden'), $count + 2), 2);

        $background = $this->ground($params->string('background', 'ink'), $palette);

        // A minimum above the maximum would reject every candidate and render an
        // empty canvas. The sliders can be dragged into that state, so the pair
        // is ordered here rather than defended against in the browser.
        $min = min($params->float('min_radius'), $params->float('max_radius') - 1.0);
        $max = max($params->float('max_radius'), $min + 1.0);

        $spec = [
            'algorithm' => 'circles',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'attempts' => $params->int('attempts'),
            'min_radius' => round($min, 3),
            'max_radius' => round($max, 3),
            'gap' => $params->float('gap'),
            'style' => $params->string('style', 'mixed'),
            'weight' => $params->float('weight'),
            'colouring' => $params->string('colouring', 'field'),
            'background' => $background,
            'palette' => $palette,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
            // Twenty-six thousand candidate points at two draws each is 360 KB of
            // stream for something a single word determines. See patterns/prng.js.
            'seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%s darts · %.0f–%.0f px · %s · %s gap · coloured %s',
                number_format($params->int('attempts')),
                $min,
                $max,
                self::STYLES[$params->string('style', 'mixed')] ?? 'Mixed',
                $params->float('gap') > 0 ? sprintf('%.1f px', $params->float('gap')) : 'no',
                strtolower(self::COLOURINGS[$params->string('colouring', 'field')] ?? 'by size'),
            ),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'attempts' => $params->int('attempts'),
                'radius_range' => sprintf('%.1f–%.1f px', $min, $max),
                // The theoretical ceiling for circles of one size; a pack of many
                // sizes beats it, which is the interesting part. Quoted rather
                // than measured because measuring it needs the finished pack, and
                // the pack is built in the browser.
                'hexagonal_limit' => '90.69% (equal circles, densest possible)',
                'note' => 'Each candidate grows until it meets a circle already placed, the canvas having no say — the circles are allowed to run off the edge, so the picture reads as a crop of something larger rather than as a diagram fitted to its frame. Candidates that cannot reach the minimum radius are discarded, which is most of the later ones.',
            ],
        );
    }
}
