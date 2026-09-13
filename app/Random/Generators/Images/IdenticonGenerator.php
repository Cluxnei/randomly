<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Oklch;
use App\Random\Rng\Rng;

/**
 * A deterministic avatar for a string.
 *
 * The odd one out in this library, and deliberately so: it never touches the Rng.
 * Every other generator here is a function of the seed, and this one is a function
 * of the *input* — `sha256("ada")` today, `sha256("ada")` in ten years, the same
 * face both times, on any machine, with nothing stored anywhere. Press Generate as
 * many times as you like; the picture will not move until the text does. That is
 * the point, and the studio says so above the canvas.
 *
 * Reading the digest as a design, per docs/08 §4:
 *
 *     hue      ← digest[0]          → OKLCH, so two inputs a hue apart are
 *                                     equally light rather than merely equally
 *                                     saturated
 *     shape    ← digest[2] mod 4    → square, circle, triangle, diamond
 *     cells    ← bits of digest[4…] → an n×⌈n/2⌉ half-grid, mirrored
 *
 * The mirror is the whole trick. The same bits laid out asymmetrically read as
 * static; folded down the vertical axis they read as a face, because that is what
 * bilateral symmetry does to a human looking at anything.
 */
final class IdenticonGenerator extends ImageGenerator
{
    private const SHAPES = ['square', 'circle', 'triangle', 'diamond'];

    public function key(): string
    {
        return 'images.identicon';
    }

    public function name(): string
    {
        return 'Identicon';
    }

    public function tagline(): string
    {
        return 'A deterministic avatar from a hash. Same input, same face.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->string('input', 'Input', default: 'randomly', max: 96, help: 'Anything at all — a username, an email, a commit hash. This, and nothing else, decides what you get.')
            ->int('size', 'Size', default: 512, min: 128, max: 1024)
            ->int('cells', 'Grid', default: 5, min: 3, max: 9, help: 'A 5×5 grid mirrored down the middle needs only 15 bits, which is why identicons were 5×5 long before anyone had bits to spare.')
            ->enum('shape', 'Cell shape', [
                'auto' => 'From the hash',
                'square' => 'Square',
                'circle' => 'Circle',
                'triangle' => 'Triangle',
                'diamond' => 'Diamond',
            ], default: 'auto')
            ->bool('two_tone', 'Two tone', default: true, help: 'A second hue, 50° along, for roughly half the live cells.')
            ->float('padding', 'Padding', default: 0.14, min: 0.0, max: 0.35, step: 0.01)
            ->float('gap', 'Cell gap', default: 0.06, min: 0.0, max: 0.4, step: 0.01)
            ->enum('background', 'Ground', ['tint' => 'Tinted', 'paper' => 'Paper', 'ink' => 'Ink'], default: 'tint')
            ->float('grain', 'Grain', default: 0.012, min: 0.0, max: 0.08, step: 0.002);
    }

    /**
     * Deliberately deterministic in the input string, not the seed.
     *
     * Two people typing the same name anywhere in the world must get the same
     * avatar, forever, with nothing stored. Randomness would defeat it entirely.
     */
    public function usesEntropy(): bool
    {
        return false;
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // $rng is accepted and ignored. See the class docblock: the whole value of
        // this generator is that the seed cannot reach the output.
        $input = $params->string('input', 'randomly');
        $digest = hash('sha256', $input, true);
        $byte = fn (int $i): int => ord($digest[$i % 32]);

        $cells = $params->int('cells', 5);
        $half = (int) ceil($cells / 2);

        $hue = $byte(0) / 255 * 360;
        // Lightness and chroma are read from the digest too, but into a narrow
        // band. A fully random L would hand some inputs an avatar the colour of
        // the page it sits on.
        $lightness = 0.58 + $byte(1) / 255 * 0.16;
        $chroma = 0.13 + $byte(3) / 255 * 0.08;

        $primary = Oklch::toHex($lightness, $chroma, $hue);
        $secondary = $params->bool('two_tone', true)
            ? Oklch::toHex($lightness - 0.1, $chroma * 0.85, fmod($hue + 50, 360))
            : $primary;

        $shape = $params->string('shape', 'auto');
        $shape = $shape === 'auto' ? self::SHAPES[$byte(2) % count(self::SHAPES)] : $shape;

        $grid = $this->grid($digest, $cells, $half);

        $spec = [
            'algorithm' => 'identicon',
            'width' => $params->int('size'),
            'height' => $params->int('size'),
            'cells' => $cells,
            // Already mirrored: 0 is empty, 1 is the primary colour, 2 the second.
            // Folding here rather than in the renderer keeps the drawing code a
            // plain loop over a grid, and makes the symmetry visible in the API
            // response itself.
            'grid' => $grid,
            'colours' => [$primary, $secondary],
            'background' => $this->identiconGround($params->string('background', 'tint'), $hue, $chroma),
            'shape' => $shape,
            'padding' => $params->float('padding'),
            'gap' => $params->float('gap'),
            'grain' => $params->float('grain'),
            'grain_seed' => $byte(31) << 24 | $byte(30) << 16 | $byte(29) << 8 | $byte(28),
        ];

        $live = count(array_filter($grid));

        return new Result(
            value: $spec,
            display: sprintf('%s → %s', $input === '' ? '(empty string)' : '"'.$input.'"', substr(bin2hex($digest), 0, 16)),
            meta: [
                'input' => $input,
                'digest' => 'sha256 '.substr(bin2hex($digest), 0, 24).'…',
                'bits_read' => $cells * $half,
                'cells_lit' => sprintf('%d of %d', $live, $cells * $cells),
                'shape' => $shape,
                'palette' => [$primary, $secondary],
                // Stated rather than left to be noticed. The meta strip will show
                // zero stream bytes used, and that number wants an explanation.
                'derived_from' => 'the input string — the seed is not consulted',
                'note' => 'This is the one generator here that does not use the seed. It reads sha256 of the input and nothing else, so pressing Generate will not change the picture — only editing the text above will. That is the point: the same string gives the same avatar on any machine, forever, with nothing stored anywhere.',
            ],
        );
    }

    /**
     * Read the half-grid out of the digest and fold it.
     *
     * Two bit streams, from opposite ends of the hash: one decides whether a cell
     * is lit at all, the other which of the two colours it gets. Taking both from
     * the same bits would correlate the colouring with the shape and the result
     * would read as striped.
     *
     * @return list<int>
     */
    private function grid(string $digest, int $cells, int $half): array
    {
        $bit = function (int $index) use ($digest): int {
            // Byte 4 onwards: the first four bytes are already spent on colour and
            // shape, and reusing them would tie the layout to the hue.
            $byte = ord($digest[4 + intdiv($index, 8) % 28]);

            return ($byte >> ($index % 8)) & 1;
        };

        $tone = function (int $index) use ($digest): int {
            // Walking backwards from the end of the digest, so the two streams
            // cannot overlap for any grid this generator will accept.
            $byte = ord($digest[31 - intdiv($index, 8) % 28]);

            return ($byte >> ($index % 8)) & 1;
        };

        $grid = array_fill(0, $cells * $cells, 0);

        for ($column = 0; $column < $half; $column++) {
            for ($row = 0; $row < $cells; $row++) {
                $index = $column * $cells + $row;

                if ($bit($index) === 0) {
                    continue;
                }

                $value = $tone($index) === 1 ? 2 : 1;

                $grid[$row * $cells + $column] = $value;
                $grid[$row * $cells + ($cells - 1 - $column)] = $value;
            }
        }

        return $grid;
    }

    private function identiconGround(string $choice, float $hue, float $chroma): string
    {
        return match ($choice) {
            'ink' => Oklch::toHex(0.14, 0.012, $hue),
            'paper' => Oklch::toHex(0.965, 0.004, 90),
            // A wash of the avatar's own hue. Neutral grounds make every identicon
            // look like it came from the same set; this one belongs to its input.
            default => Oklch::toHex(0.93, min(0.035, $chroma * 0.28), $hue),
        };
    }
}
