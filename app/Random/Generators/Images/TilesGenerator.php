<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * A grid of cells, each given a glyph, a rotation and a colour.
 *
 * The oldest trick in generative art and still one of the best, because the
 * interest is never in a tile — it is in the joins. Two neighbours whose glyphs
 * happen to meet read as one continuous figure, and the eye assembles those into
 * shapes nobody designed. It is the same effect that makes a Truchet tiling look
 * like a maze rather than like a hundred separate quarter-circles, with a larger
 * vocabulary.
 *
 * `patterns.truchet` already ships the two-orientation version of this idea and
 * is about the *tiling*: one glyph, flipped, filling the plane. This one is about
 * the *composition*: fourteen glyphs in three families, a colour per cell, and
 * some cells given a filled ground of their own so the grid acquires a large
 * scale it would not otherwise have.
 *
 * Everything is hashed from the cell's coordinates in the browser rather than
 * drawn here, exactly as truchet does. A fine grid is a couple of thousand cells
 * and the render stream is 8 KB — but the better argument is that hashing makes a
 * cell's appearance a function of *where it is* rather than of when it was drawn,
 * so the grid can be redrawn at any size and the same cell looks the same.
 */
final class TilesGenerator extends ImageGenerator
{
    private const SETS = [
        'all' => 'Everything',
        'curves' => 'Curves only',
        'lines' => 'Lines only',
        'solids' => 'Solids only',
    ];

    public function key(): string
    {
        return 'images.tiles';
    }

    public function name(): string
    {
        return 'Glyph Grid';
    }

    public function tagline(): string
    {
        return 'A random glyph and rotation per cell.';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 1000, 1000)
            ->int('tiles', 'Tiles across', default: 12, min: 2, max: 64, help: 'Measured across the long side, so a landscape canvas gets square cells rather than stretched ones.')
            ->enum('set', 'Glyphs', self::SETS, default: 'all', help: 'Curves alone read as plumbing, lines alone as a circuit diagram, solids alone as a quilt. All three together is the busiest and usually the best.')
            ->float('weight', 'Stroke weight', default: 0.17, min: 0.03, max: 0.5, step: 0.01, help: 'As a fraction of a cell. Past about 0.35 the strokes meet their neighbours and the negative space becomes the pattern.')
            ->float('density', 'Filled cells', default: 0.82, min: 0.05, max: 1.0, step: 0.02, help: 'How often a cell gets a glyph at all. The empty ones are what give the grid somewhere to breathe.')
            ->float('panel', 'Coloured panels', default: 0.22, min: 0.0, max: 1.0, step: 0.02, help: 'How often a cell gets a filled ground under its glyph. These clump into fields and give the composition a scale larger than one tile.')
            ->float('panel_alpha', 'Panel opacity', default: 0.5, min: 0.05, max: 1.0, step: 0.05)
            ->enum('background', 'Ground', self::GROUNDS, default: 'paper')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'split')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.012, min: 0.0, max: 0.08, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        /*
         * build(), not ramp() — the third generator in the project to want it,
         * alongside patterns.voronoi and images.mondrian, and for the same
         * reason. A glyph is a discrete object and nothing about a cell's colour
         * is interpolated with its neighbour's; what the palette has to do here
         * is keep two adjacent tiles apart, which is exactly the job a
         * sequential ramp is bad at.
         */
        $palette = Palette::build($rng, $params->string('palette', 'split'), $params->int('colours', 6));

        $background = $this->ground($params->string('background', 'paper'), $palette);

        $spec = [
            'algorithm' => 'tiles',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'tiles' => $params->int('tiles'),
            'set' => $params->string('set', 'all'),
            'weight' => $params->float('weight'),
            'density' => $params->float('density'),
            'panel' => $params->float('panel'),
            'panel_alpha' => $params->float('panel_alpha'),
            'background' => $background,
            'palette' => $palette,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
            // Every cell's glyph, rotation, colour and panel come out of one hash
            // of its coordinates, seeded by this. See resources/js/images/tiles.js.
            'seed' => $rng->uint32(),
        ];

        [$cols, $rows] = $this->grid($params);

        return new Result(
            value: $spec,
            display: sprintf(
                '%d×%d cells · %s · weight %.2f · %.0f%% filled',
                $cols,
                $rows,
                self::SETS[$params->string('set', 'all')] ?? 'Everything',
                $params->float('weight'),
                $params->float('density') * 100,
            ),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'cells' => $cols * $rows,
                'glyphs' => $this->glyphCount($params->string('set', 'all')),
                // Four rotations and a colour per cell on top of the glyph
                // choice. Rendered as a power of ten because PHP would print the
                // integer as 1.0E+2100 and "10^2100" is the readable form of a
                // number whose only job is to be absurd.
                'arrangements' => $this->arrangements($params, $cols * $rows),
                'note' => 'Each cell hashes its own coordinates into a glyph, one of four rotations, a colour, and whether it gets a filled panel. Nothing coordinates neighbours — every continuous line that crosses a tile boundary is a coincidence, and the picture is made of those coincidences.',
            ],
        );
    }

    /** @return array{int, int} */
    private function grid(Params $params): array
    {
        $size = max($params->int('width'), $params->int('height')) / max(1, $params->int('tiles'));

        return [
            (int) ceil($params->int('width') / $size),
            (int) ceil($params->int('height') / $size),
        ];
    }

    private function glyphCount(string $set): int
    {
        return match ($set) {
            'all' => 14,
            default => 5,
        };
    }

    private function arrangements(Params $params, int $cells): string
    {
        $perCell = $this->glyphCount($params->string('set', 'all')) * 4 * $params->int('colours', 6);

        return sprintf('10^%d', (int) round($cells * log10($perCell)));
    }
}
