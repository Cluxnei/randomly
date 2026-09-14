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
 * Life-like cellular automata: eight neighbours, two lookup tables, and a random
 * soup to start from.
 *
 *   next(c) = alive(c) ? survive[n(c)] : birth[n(c)]
 *
 * Conway's B3/S23 is one point in a space of 2¹⁸ rules, and the interesting thing
 * about that space is how little of it does anything. Almost every rule either
 * fills the plane in three generations or clears it in five. The handful that sit
 * on the boundary — Conway, HighLife, Replicator, Serviettes — are what the
 * preset list is for, and the custom field is there for anyone who wants to go
 * looking for the rest.
 *
 * The rule tables are resolved here rather than in the browser for the same
 * reason the elementary automaton's are: this is the one part of the generator
 * with a known answer. B3/S23 is birth on exactly three and survival on two or
 * three, whatever the seed or the canvas says, so it belongs on the side of the
 * fence that has a test suite. What the browser owns is the evolution, and that
 * is checked from PHP by running it — see tests/Unit/PatternGeneratorsTest.php,
 * which asserts that a block stays a block and a blinker has period two.
 *
 * **The picture is a long exposure, not a final frame.** A Conway soup settles
 * into scattered still lifes on an empty field, which is an accurate picture of
 * the rule and a poor picture of anything. The renderer accumulates a decaying
 * record of every generation instead, so still lifes burn in, oscillators sit
 * dimmer, and gliders leave trails. The plain final state is still on the menu.
 */
final class LifeGenerator extends BaseGenerator
{
    /**
     * The rules worth knowing by name.
     *
     * Every one of these is reachable by typing it into the custom field; the
     * presets exist because the rule space is overwhelmingly dull and a visitor
     * should not have to find that out by sampling it.
     *
     * @var array<string, array{string, string, string}> preset => [notation, label, note]
     */
    private const PRESETS = [
        'conway' => ['B3/S23', 'Conway — B3/S23', 'The original. Poised between growth and extinction, which is why it supports gliders, guns and, eventually, a universal computer.'],
        'highlife' => ['B36/S23', 'HighLife — B36/S23', 'Conway plus birth on six. That one extra digit gives it the replicator: a twelve-cell pattern that copies itself every twelve generations.'],
        'replicator' => ['B1357/S1357', 'Replicator — B1357/S1357', 'Birth and survival on every odd count. Every pattern reproduces itself, in the Sierpiński-like way an XOR rule does, and the plane fills with self-similar copies.'],
        'serviettes' => ['B234/S', 'Serviettes — B234/S', 'No cell ever survives its own generation; everything you see is born fresh each step. Produces expanding lacework from any starting dot.'],
        'daynight' => ['B3678/S34678', 'Day & Night — B3678/S34678', 'Symmetric under swapping live and dead, so patterns and their negatives behave identically. Gives dense continents with structured coastlines.'],
        'maze' => ['B3/S12345', 'Maze — B3/S12345', 'Conway\'s birth rule with a far more forgiving survival rule. Soup crystallises into corridors one cell wide.'],
        'coral' => ['B3/S45678', 'Coral — B3/S45678', 'Only well-supported cells live, so growth happens at the edges. Slow, thick, accreting fronts.'],
        'custom' => ['B3/S23', 'Custom (type a rule)', ''],
    ];

    /**
     * The most cells the grid may hold, and the most cell-updates a render may do.
     *
     * The grid is a full pass per generation, so the work is cells × generations
     * and both sliders multiply. 240k cells is a 600×400 grid — past the point
     * where an individual cell is visible on a 900px canvas — and 40M updates is
     * roughly a third of a second in the browser. Beyond that a slider drag stops
     * being a drag.
     */
    private const MAX_CELLS = 240000;

    private const MAX_UPDATES = 40000000;

    public function key(): string
    {
        return 'patterns.life';
    }

    public function name(): string
    {
        return 'Life';
    }

    public function tagline(): string
    {
        return 'Conway from a random soup, with a density control.';
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
            ->enum('preset', 'Rule', array_map(fn (array $p): string => $p[1], self::PRESETS), default: 'conway')
            ->string('rule', 'Custom rule', default: 'B3/S23', max: 24, help: 'B/S notation: the neighbour counts that cause a birth, then the counts a live cell survives. Only read when the preset is Custom.')
            ->int('cell', 'Cell size', default: 4, min: 1, max: 16, help: 'Pixels per cell. Small cells mean a large world and more room for structure to travel.')
            ->float('density', 'Soup density', default: 0.38, min: 0.02, max: 0.98, step: 0.01, help: 'The fraction of cells alive at generation zero. Conway is liveliest around a third; the edges of this slider are where a rule shows what it does when starved or flooded.')
            ->int('generations', 'Generations', default: 240, min: 1, max: 900)
            ->enum('mode', 'Draw', [
                'exposure' => 'Long exposure (the whole run)',
                'state' => 'Final generation only',
            ], default: 'exposure')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'magma')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        /*
         * ramp(), not build(). In exposure mode the accumulator is a genuinely
         * continuous field and categorical colours would interpolate through mud;
         * in state mode it is two values, and what matters there is that the ramp
         * pins its ends apart in lightness so live and dead cannot come out as
         * two similar greys. Both jobs want the same call.
         */
        $palette = Palette::ramp($rng, $params->string('palette', 'magma'), $params->int('colours', 6));

        $notation = $this->notation($params);
        [$birth, $survive] = self::parseRule($notation);

        [$cell, $cols, $rows] = $this->grid($params);
        $generations = min($params->int('generations'), max(1, intdiv(self::MAX_UPDATES, max(1, $cols * $rows))));

        $spec = [
            'algorithm' => 'life',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'cell' => $cell,
            'cols' => $cols,
            'rows' => $rows,
            'generations' => $generations,
            'density' => $params->float('density'),
            'birth' => $birth,
            'survive' => $survive,
            'mode' => $params->string('mode', 'exposure'),
            'palette' => $palette,
            // The soup is hashed from this one word in the browser: sixty thousand
            // coin flips is far more than the 8 KB render stream holds.
            'seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%s · %d×%d cells · %d generations · %.0f%% soup · %s',
                $notation,
                $cols,
                $rows,
                $generations,
                $params->float('density') * 100,
                $params->string('mode', 'exposure') === 'state' ? 'final frame' : 'long exposure',
            ),
            meta: [
                'palette' => $palette,
                'rule' => $notation,
                'cells' => $cols * $rows,
                'generations' => $generations,
                'cell_updates' => $cols * $rows * $generations,
                // The size of the rule space this one rule was chosen from. Nine
                // possible neighbour counts, two tables, one bit each.
                'rule_space' => '2^18 = 262,144',
                'note' => $this->note($params, $notation, $generations),
            ],
        );
    }

    /**
     * Turn B/S notation into two nine-entry lookup tables.
     *
     * Deliberately forgiving about the separator and the case, because this is a
     * free-text field someone is typing into: `b3/s23`, `B3/S23` and `3/23` all
     * mean the same rule and all three get typed. Anything that parses to no
     * birth rule at all would leave a dead canvas, so that falls back to Conway
     * rather than rendering nothing and blaming the user.
     *
     * @return array{list<int>, list<int>} birth, survive — indexed by neighbour count
     */
    public static function parseRule(string $notation): array
    {
        $parts = explode('/', strtoupper(trim($notation)));

        $birthDigits = '';
        $surviveDigits = '';

        foreach ($parts as $index => $part) {
            $digits = preg_replace('/[^0-8]/', '', $part) ?? '';

            // With a B or S letter the part says which it is; without one, the
            // convention is birth first — except for the older S/B spelling, which
            // is why the letter wins when it is there.
            if (str_contains($part, 'S')) {
                $surviveDigits .= $digits;
            } elseif (str_contains($part, 'B') || $index === 0) {
                $birthDigits .= $digits;
            } else {
                $surviveDigits .= $digits;
            }
        }

        if ($birthDigits === '') {
            [$birthDigits, $surviveDigits] = ['3', '23'];
        }

        $table = static function (string $digits): array {
            $out = array_fill(0, 9, 0);

            foreach (str_split($digits) as $digit) {
                $out[(int) $digit] = 1;
            }

            return $out;
        };

        // Birth on zero neighbours makes every empty cell in the infinite plane
        // light up at once, which on a torus is a solid rectangle and nothing
        // else, forever. It is a legal rule and an unwatchable one.
        $birth = $table($birthDigits);
        $birth[0] = 0;

        return [$birth, $table($surviveDigits)];
    }

    private function notation(Params $params): string
    {
        $preset = $params->string('preset', 'conway');

        if ($preset !== 'custom' && isset(self::PRESETS[$preset])) {
            return self::PRESETS[$preset][0];
        }

        [$birth, $survive] = self::parseRule($params->string('rule', 'B3/S23'));

        // Echoed back in canonical form rather than as typed, so the caption says
        // what the automaton actually ran rather than what was in the box.
        return sprintf(
            'B%s/S%s',
            implode('', array_keys($birth, 1, true)),
            implode('', array_keys($survive, 1, true)),
        );
    }

    /**
     * Cell size grown until the grid fits the budget.
     *
     * Growing the cell rather than cropping the canvas: the alternative is a
     * 2048px canvas at one pixel per cell, which is a million cells and a
     * multi-second render for a picture where no individual cell is visible
     * anyway.
     *
     * @return array{int, int, int} cell, cols, rows
     */
    private function grid(Params $params): array
    {
        $cell = max(1, $params->int('cell', 4));

        while (true) {
            $cols = (int) ceil($params->int('width') / $cell);
            $rows = (int) ceil($params->int('height') / $cell);

            if ($cols * $rows <= self::MAX_CELLS) {
                return [$cell, $cols, $rows];
            }

            $cell++;
        }
    }

    private function note(Params $params, string $notation, int $generations): string
    {
        $preset = $params->string('preset', 'conway');
        $known = self::PRESETS[$preset][2] ?? '';

        $drawing = $params->string('mode', 'exposure') === 'state'
            ? sprintf('Drawn as generation %d alone.', $generations)
            : sprintf('Drawn as a long exposure of all %d generations: every cell keeps a decaying record of how often it has been alive, so still lifes burn in brightest, oscillators sit dimmer, and a glider leaves a comet trail behind it.', $generations);

        return trim($known.' '.$drawing.sprintf(' The world is a torus — %s wraps at every edge — so nothing falls off.', $notation));
    }
}
