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
 * Elementary cellular automata: one row of cells, one rule, and time running
 * downwards.
 *
 * A cell's next state depends on three cells — itself and its two neighbours — so
 * a rule is a lookup table from eight neighbourhoods to eight bits, and the whole
 * rule space is exactly 256 entries wide. That smallness is the point: it is the
 * shortest honest route from "a number between 0 and 255" to structure nobody
 * designed. Rule 30 is chaotic enough that Mathematica shipped it as a random
 * number generator; rule 110 is Turing-complete; rule 90 is XOR and draws the
 * Sierpiński triangle exactly.
 *
 * The rule table is resolved here rather than in the browser because it is the
 * one part of this generator with a known answer — rule 90 is [0,1,0,1,1,0,1,0]
 * and nothing about the seed or the canvas can change that — so it is worth
 * having under test on the side that has a test suite.
 */
final class AutomatonGenerator extends BaseGenerator
{
    /**
     * The rules worth knowing by name, with what makes each one worth knowing.
     *
     * Every one of these is reachable from the rule slider; the presets exist so
     * that a visitor who does not already know which of the 256 are interesting
     * is not left dragging through the ninety per cent that die immediately.
     *
     * @var array<string, array{int, string, string}> preset => [rule, label, note]
     */
    private const PRESETS = [
        'custom' => [0, 'Custom (use the slider)', ''],
        'r30' => [30, 'Rule 30 — chaotic', 'Chaotic enough that Mathematica used its centre column as a random number generator.'],
        'r110' => [110, 'Rule 110 — Turing-complete', 'Proved capable of universal computation, from a four-bit rule.'],
        'r90' => [90, 'Rule 90 — Sierpiński', 'The XOR of its two neighbours, which draws the Sierpiński triangle exactly.'],
        'r150' => [150, 'Rule 150 — nested XOR', 'XOR of all three cells: a denser relative of rule 90.'],
        'r184' => [184, 'Rule 184 — traffic', 'A conserved-density rule that models cars queuing and clearing.'],
    ];

    /** Wolfram's classification, for the rules where it is settled. */
    private const CLASSES = [
        30 => 'III — chaotic',
        90 => 'III — chaotic',
        110 => 'IV — complex',
        150 => 'III — chaotic',
        184 => 'II — periodic',
    ];

    public function key(): string
    {
        return 'patterns.automaton';
    }

    public function name(): string
    {
        return 'Elementary Automata';
    }

    public function tagline(): string
    {
        return 'All 256 rules, including the one that is a PRNG.';
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
            ->enum('preset', 'Rule', array_map(fn (array $p): string => $p[1], self::PRESETS), default: 'r30')
            ->int('rule', 'Rule number', default: 30, min: 0, max: 255, help: 'Every rule is one byte: bit i is what the neighbourhood with binary value i becomes. Only used when the preset is Custom.')
            ->enum('start', 'First row', [
                'centre' => 'A single live cell',
                'random' => 'Random, at a density',
            ], default: 'centre', help: 'One cell shows the rule\'s own geometry; a random row shows what it does to noise.')
            ->float('density', 'Start density', default: 0.5, min: 0.02, max: 0.98, step: 0.02, help: 'Only used by the random first row.')
            ->int('cell', 'Cell size', default: 3, min: 1, max: 16, help: 'Pixels per cell. Smaller means more generations on screen.')
            ->bool('wrap', 'Wrap edges', default: true, help: 'Off, the world has walls and the pattern reflects off them.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'mono');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $rule = $this->rule($params);

        /*
         * Two colours, from ramp() rather than build(), which is the one place in
         * this module where the field/categorical rule does not decide it.
         *
         * Nothing here interpolates — a cell is alive or it is not — so the mud
         * that build() would cause elsewhere cannot happen. What matters instead
         * is contrast, and only ramp() guarantees it: its ends are pinned at
         * L=0.16 and L=0.92, while two categorical colours can easily land at the
         * same lightness in different hues and turn the structure to soup.
         */
        $palette = Palette::ramp($rng, $params->string('palette', 'mono'), 2);

        // A single uint32 rather than a per-cell draw: the first row can be two
        // thousand cells wide and the browser's render stream is 8 KB.
        $seed = $rng->uint32();

        $spec = [
            'algorithm' => 'automaton',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'rule' => $rule,
            'table' => self::table($rule),
            'start' => $params->string('start', 'centre'),
            'density' => $params->float('density'),
            'cell' => $params->int('cell'),
            'wrap' => $params->bool('wrap'),
            'palette' => $palette,
            'seed' => $seed,
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $rule),
            meta: [
                'palette' => $palette,
                'rule' => $rule,
                // The rule *is* its binary expansion; showing it next to the
                // number is what makes the lookup-table story land.
                'rule_binary' => str_pad(decbin($rule), 8, '0', STR_PAD_LEFT),
                'wolfram_class' => self::CLASSES[$rule] ?? null,
                'note' => self::PRESETS[$params->string('preset', 'r30')][2] ?: null,
                'generations' => intdiv($params->int('height'), max(1, $params->int('cell'))),
                'cells_per_row' => intdiv($params->int('width'), max(1, $params->int('cell'))),
            ],
        );
    }

    /**
     * The rule's lookup table, indexed by the neighbourhood read as a 3-bit number
     * with the left neighbour as the high bit.
     *
     * This is the whole definition of "rule n" and it is worth stating as code
     * rather than folding into the renderer: rule 90 must be [0,1,0,1,1,0,1,0]
     * and rule 30 must be [0,1,1,1,1,0,0,0], which is a known answer a test can
     * hold against.
     *
     * @return list<int>
     */
    public static function table(int $rule): array
    {
        $table = [];

        for ($i = 0; $i < 8; $i++) {
            $table[] = ($rule >> $i) & 1;
        }

        return $table;
    }

    private function rule(Params $params): int
    {
        $preset = $params->string('preset', 'r30');

        return $preset === 'custom' || ! isset(self::PRESETS[$preset])
            ? $params->int('rule') & 255
            : self::PRESETS[$preset][0];
    }

    private function describe(Params $params, int $rule): string
    {
        return sprintf(
            'Rule %d (%s) · %s · %d generations',
            $rule,
            str_pad(decbin($rule), 8, '0', STR_PAD_LEFT),
            $params->string('start') === 'random'
                ? sprintf('random row at %d%%', (int) round($params->float('density') * 100))
                : 'one live cell',
            intdiv($params->int('height'), max(1, $params->int('cell'))),
        );
    }
}
