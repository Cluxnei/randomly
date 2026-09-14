<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Oklch;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Recursive subdivision: cut a rectangle in two, keep going while the pieces are
 * still big enough to cut.
 *
 *   split at U(0.3, 0.7)   ·   recurse while area > t
 *
 * The bounds on the split are the entire design. U(0, 1) produces slivers, which
 * subdivide into finer slivers, and the result is a shredded rectangle; U(0.45,
 * 0.55) produces a grid, because every cut is near the middle and the shapes all
 * converge on the same proportions. Between those two is a composition, and the
 * slider is exposed precisely because moving it to either end is the fastest way
 * to see why the middle is where the good pictures are.
 *
 * **The subdivision runs here rather than in the browser.** It is the one canvas
 * generator whose structure is small enough to ship — a couple of hundred
 * rectangles — and having it on this side buys a real test: the cells must tile
 * the canvas exactly, with no gap and no overlap, which is a property a
 * subdivision loses silently the first time a rounding is done in the wrong
 * place.
 *
 * Colour uses Palette::build(), not ramp(). This is the second generator in the
 * project to want categorical colour, for the same reason patterns.voronoi does:
 * the cells are unrelated objects that must be told apart, and a sequential ramp
 * would give two adjacent rectangles nearly the same colour. Most cells stay
 * unpainted, because a Mondrian that is mostly colour is a quilt.
 */
final class MondrianGenerator extends ImageGenerator
{
    /**
     * The most cells a composition may hold.
     *
     * Not a performance limit — two hundred rectangles draw instantly — but a
     * spec-size one. The whole justification for shipping a description instead
     * of pixels is that the description is tiny, and the studio holds canvas
     * generators to a few kilobytes.
     */
    private const MAX_CELLS = 180;

    public function key(): string
    {
        return 'images.mondrian';
    }

    public function name(): string
    {
        return 'Mondrian';
    }

    public function tagline(): string
    {
        return 'Recursive subdivision, split at U(0.3, 0.7).';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 900, 900)
            ->float('cut_min', 'Cut range, low', default: 0.3, min: 0.02, max: 0.5, step: 0.01, help: 'Where along a rectangle a cut may fall. Push this to 0.02 and the composition shreds into slivers; push it to 0.5 and every cut lands in the middle, which is a grid.')
            ->float('threshold', 'Stop below', default: 0.035, min: 0.002, max: 0.25, step: 0.002, help: 'A rectangle smaller than this fraction of the canvas is left whole. The single strongest control over how busy the result is.')
            ->float('squareness', 'Cut the long side', default: 0.78, min: 0.0, max: 1.0, step: 0.02, help: 'How often a cut crosses the longer side. At 1 every rectangle is pushed towards square; at 0 the composition drifts into long bands.')
            ->float('fill', 'Painted cells', default: 0.34, min: 0.0, max: 1.0, step: 0.02, help: 'The fraction that get a colour rather than staying paper. Mondrian used very few, and a canvas that is mostly colour stops reading as a composition and starts reading as a quilt.')
            ->float('stroke', 'Line weight', default: 9.0, min: 0.0, max: 30.0, step: 0.5, help: 'The gutter between cells, in pixels. The lines are not drawn — they are the ground showing through.')
            ->enum('background', 'Lines', self::GROUNDS, default: 'ink')
            ->bool('paper', 'Unpainted cells are white', default: true, help: 'Off, the unpainted cells take the palette\'s lightest stop instead, which keeps the whole composition inside one colour scheme.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'triadic')
            ->int('colours', 'Palette size', default: 5, min: 2, max: 10)
            ->float('grain', 'Grain', default: 0.012, min: 0.0, max: 0.08, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // build(), not ramp(): see the class comment. Cells are discrete objects
        // and two neighbours have to be distinguishable.
        $palette = Palette::build($rng, $params->string('palette', 'triadic'), $params->int('colours', 5));

        $background = $this->ground($params->string('background', 'ink'), $palette);

        $width = $params->int('width');
        $height = $params->int('height');

        $cells = $this->subdivide($rng, $params, $width, $height);
        $painted = $this->paint($rng, $cells, $params);

        $spec = [
            'algorithm' => 'mondrian',
            'width' => $width,
            'height' => $height,
            'stroke' => $params->float('stroke'),
            'paper' => $params->bool('paper')
                ? Oklch::toHex(0.965, 0.004, 90)
                : $palette[count($palette) - 1],
            'background' => $background,
            'palette' => $palette,
            'cells' => $painted,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
        ];

        $coloured = count(array_filter($painted, fn (array $c): bool => $c[4] >= 0));

        return new Result(
            value: $spec,
            display: sprintf(
                '%d cells · %d painted · cuts in U(%.2f, %.2f) · %.0f px lines',
                count($painted),
                $coloured,
                $params->float('cut_min'),
                1 - $params->float('cut_min'),
                $params->float('stroke'),
            ),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'cells' => count($painted),
                'painted' => $coloured,
                'cut_range' => sprintf('U(%.2f, %.2f)', $params->float('cut_min'), 1 - $params->float('cut_min')),
                'note' => sprintf(
                    'Every rectangle larger than %.1f%% of the canvas is cut in two, at a fraction drawn from U(%.2f, %.2f) of whichever side the squareness control selected. Nothing draws the black lines: they are the ground, showing through a %.0f px gutter left around every cell.',
                    $params->float('threshold') * 100,
                    $params->float('cut_min'),
                    1 - $params->float('cut_min'),
                    $params->float('stroke'),
                ),
            ],
        );
    }

    /**
     * Cut the canvas up.
     *
     * Breadth-first through a queue rather than depth-first through recursion,
     * which is what makes the cell cap behave: the cap stops the composition at a
     * consistent level of detail all over, where a depth-first walk that hit the
     * cap would leave one corner finely divided and the rest untouched.
     *
     * Coordinates stay as floats until the very end. Rounding each cut as it is
     * made accumulates, and a subdivision six levels deep would then leave gutters
     * that are a pixel wider in some places than others — which the eye reads as a
     * mistake long before it can say what it is looking at.
     *
     * @return list<array{float, float, float, float}> x, y, width, height
     */
    private function subdivide(Rng $rng, Params $params, int $width, int $height): array
    {
        $low = min(0.49, $params->float('cut_min'));
        $span = 1 - 2 * $low;
        $threshold = $params->float('threshold') * $width * $height;
        $squareness = $params->float('squareness');

        $queue = [[0.0, 0.0, (float) $width, (float) $height]];
        $done = [];

        while ($queue !== []) {
            [$x, $y, $w, $h] = array_shift($queue);

            // The cap counts everything still outstanding as well as everything
            // finished, plus the rectangle in hand — stopping only on the finished
            // count would let the queue overshoot by its own length, and forgetting
            // the one just popped overshoots by exactly one, which is the kind of
            // off-by-one a cap of 180 only reveals as a 181.
            if ($w * $h <= $threshold || count($queue) + count($done) + 1 >= self::MAX_CELLS) {
                $done[] = [$x, $y, $w, $h];

                continue;
            }

            $vertical = $rng->bool($squareness) ? $w >= $h : $rng->bool();
            $at = $low + $rng->float() * $span;

            if ($vertical) {
                $cut = $w * $at;
                $queue[] = [$x, $y, $cut, $h];
                $queue[] = [$x + $cut, $y, $w - $cut, $h];
            } else {
                $cut = $h * $at;
                $queue[] = [$x, $y, $w, $cut];
                $queue[] = [$x, $y + $cut, $w, $h - $cut];
            }
        }

        return $done;
    }

    /**
     * Give some of the cells a colour, and weight the choice by area.
     *
     * A uniform draw scatters colour evenly over a composition whose cells differ
     * in size by two orders of magnitude, so most of the paint lands on specks
     * and the large fields stay empty. Weighting towards the larger cells is what
     * puts the colour where it can be seen — and it is what Mondrian did, which
     * is not a coincidence.
     *
     * @param  list<array{float, float, float, float}>  $cells
     * @return list<array{int, int, int, int, int}> x, y, w, h, palette index (−1 for paper)
     */
    private function paint(Rng $rng, array $cells, Params $params): array
    {
        $areas = array_map(fn (array $c): float => $c[2] * $c[3], $cells);
        $largest = max([1.0, ...$areas]);

        $out = [];
        $colours = $params->int('colours', 5);

        foreach ($cells as $i => [$x, $y, $w, $h]) {
            // Square root rather than the area itself: raw area spans a factor of
            // a hundred here, and weighting by it would paint the three biggest
            // cells and nothing else.
            $weight = sqrt($areas[$i] / $largest);
            $index = $rng->float() < $params->float('fill') * (0.45 + 0.85 * $weight)
                ? $rng->intBetween(0, $colours - 1)
                : -1;

            $out[] = [
                (int) round($x),
                (int) round($y),
                (int) round($x + $w) - (int) round($x),
                (int) round($y + $h) - (int) round($y),
                $index,
            ];
        }

        return $out;
    }
}
