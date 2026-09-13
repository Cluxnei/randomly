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
 * Two syllables, ending in a vowel — the shape of every startup name since 2010.
 *
 * Same engine as the name forge, pinned to the one pattern that reads as a brand:
 * short, open-ended, and trivially sayable by someone who has only seen it
 * written down. The domain beside each name is a *pattern hint*, not a lookup —
 * we never ask a registrar anything, and saying so is better than implying an
 * availability check the page did not perform.
 */
final class BrandGenerator extends BaseGenerator
{
    /** Two is the brand shape. Three starts sounding like a pharmaceutical. */
    private const SYLLABLES = 2;

    private const TLDS = ['com' => '.com', 'io' => '.io', 'ai' => '.ai', 'co' => '.co', 'app' => '.app'];

    /** Nordic vowels would need punycode to be a real domain; the hint should be typeable. */
    private const DOMAIN_FOLD = ['ø' => 'o', 'å' => 'a', 'æ' => 'ae'];

    public function key(): string
    {
        return 'words.brand';
    }

    public function name(): string
    {
        return 'Brand Names';
    }

    public function tagline(): string
    {
        return 'Two open syllables and a plausible domain — no registrar was consulted.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 12, min: 1, max: 60)
            ->enum('style', 'Style', SyllableGrammar::options(), default: 'brand')
            ->enum('tld', 'Domain hint', self::TLDS, default: 'com', help: 'A pattern hint only. Nothing here checks whether it is registered.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $style = $params->string('style');
        $tld = self::TLDS[$params->string('tld')] ?? '.com';

        $names = [];
        $bits = 0.0;

        for ($i = 0; $i < $params->int('count'); $i++) {
            // 'open' overrides whatever the flavour would normally do with its last
            // syllable, so latin loses its -us and nordic loses its hard coda. That
            // is the whole difference between a name forge output and a brand.
            $forged = SyllableGrammar::forge($rng, $style, self::SYLLABLES, finalMode: 'open');
            $bits += $forged['bits'];

            $names[] = [
                'name' => SyllableGrammar::capitalise($forged['word']),
                'domain' => strtr($forged['word'], self::DOMAIN_FOLD).$tld,
                'syllables' => $forged['syllables'],
            ];
        }

        return new Result(
            value: $names,
            display: implode(PHP_EOL, array_map(
                fn (array $n): string => sprintf('%s · %s', $n['name'], $n['domain']),
                $names,
            )),
            meta: [
                'style' => $style,
                'syllables' => self::SYLLABLES,
                'domain_check' => 'none — the domain is a pattern hint, not an availability result',
                'entropy_out_bits' => round($bits, 2),
                'entropy_note' => 'Summed surprisal of the weighted draws. Two syllables is a small space by design; short names are guessable and that is the price of being memorable.',
            ],
        );
    }
}
