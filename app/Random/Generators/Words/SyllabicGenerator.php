<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Names built from a syllable grammar rather than looked up in a list.
 *
 * The Markov chain next door learns what English looks like and occasionally
 * produces something nobody can pronounce. This cannot: onset? nucleus coda? is a
 * grammar that has no way to emit an illegal cluster, so every word it returns is
 * sayable by construction. The tradeoff is that it only knows the flavours we
 * wrote down, which is why they live in resources/data/syllables.json as weights
 * and adding one is a JSON edit.
 */
final class SyllabicGenerator extends BaseGenerator
{
    public function key(): string
    {
        return 'words.syllabic';
    }

    public function name(): string
    {
        return 'Name Forge';
    }

    public function tagline(): string
    {
        return 'Syllable grammars rather than a list lookup — elvish, nordic, latin, brand.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 10, min: 1, max: 60)
            ->enum('flavour', 'Flavour', SyllableGrammar::options(), default: 'elvish')
            ->int('syllables', 'Syllables', default: 3, min: 2, max: 4)
            ->bool('capitalise', 'Capitalise', default: true, help: 'These are names, so they start with a capital by default.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $flavour = $params->string('flavour');
        $capitalise = $params->bool('capitalise');

        $names = [];
        $bits = 0.0;

        for ($i = 0; $i < $params->int('count'); $i++) {
            $forged = SyllableGrammar::forge($rng, $flavour, $params->int('syllables'));
            $bits += $forged['bits'];

            $names[] = [
                'name' => $capitalise ? SyllableGrammar::capitalise($forged['word']) : $forged['word'],
                // The syllables travel with the name because the split is the whole
                // explanation of how it was built: "Thae-lin-dor" says more than
                // "Thaelindor" ever could.
                'syllables' => $forged['syllables'],
            ];
        }

        return new Result(
            value: $names,
            display: implode(PHP_EOL, array_column($names, 'name')),
            meta: [
                'flavour' => $flavour,
                'flavour_note' => SyllableGrammar::note($flavour),
                'alphabet' => SyllableGrammar::alphabet($flavour),
                'entropy_out_bits' => round($bits, 2),
                'entropy_note' => 'Summed surprisal of the weighted draws. The tables are deliberately uneven, so this is below log₂(combinations).',
            ],
        );
    }
}
