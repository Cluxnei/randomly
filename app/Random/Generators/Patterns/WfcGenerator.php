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
 * Wave Function Collapse, overlapping model.
 *
 * Show it a small picture and it produces a large one that is *locally* like it:
 * every N×N window of the output appears somewhere in the input. Nothing else is
 * specified — no rules about what a corridor is, no grammar, no tiles with edges
 * labelled by hand. The structure in the output is entirely an inference from
 * fourteen rows of digits.
 *
 * Mechanically it is a constraint satisfaction problem. Every N×N window of the
 * sample becomes a *pattern*, with its frequency as a weight; two patterns are
 * compatible in a direction when the cells they share under that offset agree;
 * every output cell starts holding all patterns at once. The solver repeatedly
 * collapses the cell with the least Shannon entropy to a single pattern drawn by
 * weight, and propagates the consequences until nothing more can be removed.
 *
 * Two details are the whole difference between working and not.
 *
 * **Least entropy, not scan order.** Collapsing the most constrained cell first
 * means every decision is made while its neighbours still have room to
 * accommodate it. Scan order walls the solver into a contradiction within a few
 * rows, every time.
 *
 * **Backtracking.** The constraint graph has cycles, so propagation is not
 * complete — a cell can be left holding patterns that no global assignment can
 * use, and the contradiction surfaces several decisions later. The renderer
 * journals every change and rewinds to the last decision to refute it. Past that
 * it restarts; past that it fills the remainder with the most likely pattern. It
 * never fails to draw.
 *
 * The samples are bundled here rather than uploaded, and they are small on
 * purpose. The cost of the solve is cells × patterns × adjacency, and a sample
 * twice as wide is roughly four times the patterns; fourteen rows of digits is
 * enough to specify a knot, a floor plan or a cave system, and small enough that
 * a slider drag stays a drag.
 */
final class WfcGenerator extends BaseGenerator
{
    /**
     * The bundled tileset.
     *
     * Every sample is read *periodically* by the extractor — the N×N window wraps
     * at the edges — so each one is authored to tile. That is not a nicety: it is
     * what makes the pattern set closed under translation, and it removes every
     * boundary special case from the solver.
     *
     * Colour indices are ordered rather than arbitrary: 0 is always the ground
     * and the higher indices are the structure laid on it, in increasing
     * prominence. That ordering is why this generator wants Palette::ramp().
     *
     * @var array<string, array{string, list<string>, string}> key => [label, rows, note]
     */
    private const SAMPLES = [
        'knot' => ['Knot', [
            '000121000',
            '000121000',
            '000121000',
            '111111111',
            '222222222',
            '111111111',
            '000121000',
            '000121000',
            '000121000',
        ], 'Two cords crossing, one passing over the other. The output is a woven net: the solver has inferred that a cord may run straight, may cross, and may not simply stop.'],

        'maze' => ['Maze', [
            '0000000000',
            '0111111110',
            '0100000010',
            '0101110010',
            '0101010010',
            '0101011110',
            '0100010000',
            '0111110000',
            '0000000000',
        ], 'One irregular run of pipework. Everything that makes the output interesting — corners, dead ends, long straights, the way a corridor never quite repeats — is inferred from those nine rows and nothing else.'],

        'rooms' => ['Rooms', [
            '00000000000',
            '01111100000',
            '01000100000',
            '01000100000',
            '01111111110',
            '00000100010',
            '00000100010',
            '00000111110',
            '00000000000',
        ], 'Two rooms sharing a wall. Out of them comes a floor plan of rooms at every size, because nothing in the sample says how big a room is — only that a wall may turn a corner, meet another, or run on.'],

        'circuit' => ['Circuit', [
            '000000000000',
            '011111000000',
            '010002000000',
            '010001111100',
            '010000000100',
            '011111000100',
            '000001000100',
            '000001111100',
            '000000000000',
        ], 'Traces and one pad. The pad appears once in the sample, so it appears rarely in the output and always where a trace turns — a frequency and a placement rule the solver was never told, only shown.'],

        'weave' => ['Weave', [
            '0022000',
            '0011000',
            '2211122',
            '0011000',
            '0011000',
            '2211122',
            '0011000',
        ], 'Posts and beams, deliberately asymmetric. The most ornamental of the set, and the one the rotation symmetries do the most for: with them on, the weave runs both ways at once.'],

        'blossoms' => ['Blossoms', [
            '0000000000',
            '0011110000',
            '0111112000',
            '0122211100',
            '0112211100',
            '0011111000',
            '0000110000',
            '0000000000',
        ], 'One irregular blob with a lighter core. It scatters rather than joining up — the sample has no rule that lets two blobs touch — so the output is a field of them, occasionally caught mid-collision.'],
    ];

    /** The most output cells the solver will be given. */
    private const MAX_CELLS = 3400;

    public function key(): string
    {
        return 'patterns.wfc';
    }

    public function name(): string
    {
        return 'Wave Function Collapse';
    }

    public function tagline(): string
    {
        return 'Overlapping model, min-entropy heuristic, backtracking.';
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
            ->int('width', 'Width', default: 960, min: 200, max: 2048)
            ->int('height', 'Height', default: 640, min: 200, max: 2048)
            ->enum('sample', 'Sample', array_map(fn (array $s): string => $s[0], self::SAMPLES), default: 'knot')
            ->int('n', 'Window size (N)', default: 3, min: 2, max: 3, help: 'How much context a pattern carries. N = 2 knows only which pairs of cells may touch and produces something loose; N = 3 knows whole corners, and is where the output starts looking authored.')
            ->enum('symmetry', 'Symmetry', [
                '1' => 'None — the sample as drawn',
                '2' => 'Mirrored',
                '4' => 'All four rotations',
                '8' => 'Rotations and mirrors',
            ], default: '8', help: 'Which transforms of each pattern are also admitted. More symmetry means a richer output from the same sample, and a larger pattern set to propagate through.')
            ->int('tiles', 'Cells across', default: 74, min: 8, max: 140, help: 'Measured across the long side. The solve is cells × patterns × adjacency, so this is the control that decides whether the render is instant or merely fast.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'viridis')
            ->float('grain', 'Grain', default: 0.01, min: 0.0, max: 0.06, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $key = $params->string('sample', 'knot');
        [$label, $rows, $note] = self::SAMPLES[$key] ?? self::SAMPLES['knot'];

        $colours = $this->colourCount($rows);

        /*
         * ramp(), not build(), and this one is worth explaining because the
         * output *is* categorical — it is a tiling, and a tile is a discrete
         * thing.
         *
         * The reason is the same one patterns.truchet gives. Index 0 in every
         * bundled sample is the ground and the higher indices are structure laid
         * on it, in increasing prominence, so what the palette has to guarantee
         * is that the ground and the ink are far apart in lightness. ramp() pins
         * its ends apart by construction; build() would happily return three
         * colours that all read as the same grey in a photograph, and a knot you
         * cannot see is not a knot.
         */
        $palette = Palette::ramp($rng, $params->string('palette', 'viridis'), $colours);

        [$cols, $gridRows] = $this->grid($params);

        $spec = [
            'algorithm' => 'wfc',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'sample' => ['key' => $key, 'rows' => $rows],
            'n' => $params->int('n'),
            'symmetry' => (int) $params->string('symmetry', '8'),
            'cols' => $cols,
            'rows' => $gridRows,
            'palette' => $palette,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
            // The solver draws a number per collapse and per tie-break, which for
            // a few thousand cells is far more than the 8 KB render stream holds.
            // See patterns/prng.js for the argument.
            'seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%s · N = %d · %s · %d×%d cells',
                $label,
                $params->int('n'),
                $this->symmetryLabel($params->string('symmetry', '8')),
                $cols,
                $gridRows,
            ),
            meta: [
                'palette' => $palette,
                'sample' => $label,
                'sample_size' => sprintf('%d×%d', strlen($rows[0]), count($rows)),
                'window' => sprintf('%d×%d', $params->int('n'), $params->int('n')),
                'symmetry' => $this->symmetryLabel($params->string('symmetry', '8')),
                'cells' => $cols * $gridRows,
                // How many N×N windows the sample yields before deduplication.
                // The solver's real pattern count is lower and is only known once
                // the browser has extracted them, which is why this is stated as
                // a ceiling rather than a count.
                'patterns_before_dedup' => strlen($rows[0]) * count($rows) * (int) $params->string('symmetry', '8'),
                'output_is_periodic' => false,
                'note' => $note.' The output has edges rather than wrapping: a periodic output would impose a global constraint that no assignment can always satisfy — diagonal stripes of period four cannot close up around a grid 74 cells wide — and the solver would spend its whole budget discovering that.',
            ],
        );
    }

    /**
     * The output grid, sized to the canvas and capped.
     *
     * Cells are square: the tile count is measured across the long side and the
     * short side gets however many fit. Stretching cells to make the counts come
     * out round would shear every pattern in the sample.
     *
     * @return array{int, int} cols, rows
     */
    private function grid(Params $params): array
    {
        $width = $params->int('width');
        $height = $params->int('height');
        $tiles = max(2, $params->int('tiles'));

        while (true) {
            $size = max($width, $height) / $tiles;
            $cols = max(2, (int) ceil($width / $size));
            $rows = max(2, (int) ceil($height / $size));

            if ($cols * $rows <= self::MAX_CELLS || $tiles <= 8) {
                return [$cols, $rows];
            }

            // Coarsen rather than crop. A cropped grid would silently render a
            // corner of the picture the parameters asked for.
            $tiles--;
        }
    }

    /** @param list<string> $rows */
    private function colourCount(array $rows): int
    {
        $highest = 0;

        foreach ($rows as $row) {
            foreach (str_split($row) as $char) {
                $highest = max($highest, (int) $char);
            }
        }

        return $highest + 1;
    }

    private function symmetryLabel(string $symmetry): string
    {
        return match ($symmetry) {
            '1' => 'as drawn',
            '2' => 'mirrored',
            '4' => '4 rotations',
            default => '8 symmetries',
        };
    }
}
