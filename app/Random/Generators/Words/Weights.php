<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

use App\Random\Rng\Rng;

/**
 * Weighted string choice that also reports what the choice cost in bits.
 *
 * The grown-word generators draw from hand-tuned tables, so their output is *not*
 * uniform and quoting log₂(options) would overstate every name they produce. What
 * each draw actually contributes is its surprisal, −log₂(p) — the number of bits
 * an attacker would have to guess to land on this exact word. Summed along a word
 * it is the only entropy claim these generators can honestly make, and it is
 * always smaller than the naive count. Passphrases are the exception and get
 * exact arithmetic instead: a uniform list of 7,776 needs no estimate.
 */
final class Weights
{
    /**
     * @param  array<string, int|float>  $weights
     * @param  float|null  $bits  accumulates the surprisal of this draw
     */
    public static function draw(Rng $rng, array $weights, ?float &$bits = null): string
    {
        $choice = (string) $rng->weighted(array_keys($weights), array_values($weights));
        $total = array_sum($weights);

        if ($total > 0 && ($weights[$choice] ?? 0) > 0) {
            $bits = ($bits ?? 0.0) + -log($weights[$choice] / $total, 2);
        }

        return $choice;
    }

    /**
     * Draw with some options removed — used to refuse an empty onset when the
     * previous syllable already ended on a vowel.
     *
     * Falls back to the full table if the exclusion empties it, because a flavour
     * whose onset table is nothing but the empty string is a data problem and
     * should produce an odd word, not an exception on a live page.
     *
     * @param  array<string, int|float>  $weights
     * @param  list<string>  $without
     */
    public static function drawExcept(Rng $rng, array $weights, array $without, ?float &$bits = null): string
    {
        $remaining = array_diff_key($weights, array_fill_keys($without, true));

        return self::draw($rng, $remaining === [] ? $weights : $remaining, $bits);
    }
}
