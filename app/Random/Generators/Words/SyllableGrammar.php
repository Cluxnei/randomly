<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

use App\Random\Rng\Rng;

/**
 * Deterministic phonotactics: word := syllable{2,4}, syllable := onset? nucleus coda?.
 *
 * Where the Markov chain learns what English looks like and sometimes produces
 * mush, this builds words from a grammar that cannot produce an unpronounceable
 * cluster in the first place. Flavours are pure weight tables in
 * resources/data/syllables.json, so adding one is a JSON edit.
 */
final class SyllableGrammar
{
    private const VOWELS = 'aeiouyåøæ';

    /** Three consonants in a row is "strand"; four is a typo. */
    private const MAX_CLUSTER = 3;

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @return array<string, array<string, mixed>> */
    public static function flavours(): array
    {
        return self::data()['flavours'];
    }

    /** @return array<string, string> key => label, for a generator's enum schema */
    public static function options(): array
    {
        return array_map(fn (array $f): string => $f['label'], self::flavours());
    }

    public static function note(string $flavour): string
    {
        return self::flavour($flavour)['note'];
    }

    /**
     * Every letter a flavour can emit — what its output is allowed to be made of.
     *
     * Derived from the tables rather than written down separately, so a flavour
     * that gains a cluster cannot drift out of sync with its own alphabet.
     */
    public static function alphabet(string $flavour): string
    {
        $f = self::flavour($flavour);
        $pieces = [
            ...array_keys($f['onset']),
            ...array_keys($f['nucleus']),
            ...array_keys($f['coda']),
            ...array_keys($f['final']['weights'] ?? []),
        ];

        $letters = array_unique(preg_split('//u', implode('', $pieces), -1, PREG_SPLIT_NO_EMPTY));
        sort($letters);

        return implode('', $letters);
    }

    /**
     * Build one word.
     *
     * @param  string|null  $finalMode  override the flavour's ending: 'open' forces a
     *                                  vowel ending, which is what the brand generator wants
     *                                  out of tables that would otherwise close a syllable.
     * @return array{word: string, syllables: list<string>, bits: float}
     */
    public static function forge(Rng $rng, string $flavour, int $syllables, ?string $finalMode = null): array
    {
        $f = self::flavour($flavour);
        $syllables = max(1, min(6, $syllables));
        $mode = $finalMode ?? ($f['final']['mode'] ?? 'coda');
        $bits = 0.0;

        $parts = [];
        $word = '';

        for ($i = 0; $i < $syllables; $i++) {
            $last = $i === $syllables - 1;

            // Every piece is drawn from what the previous letters actually allow,
            // rather than drawn freely and patched up afterwards. Filtering the
            // table before the draw keeps the reported surprisal honest — it is
            // computed over the options that were really on offer.
            $onset = Weights::draw($rng, self::legal($f['onset'], $word, allowEmpty: ! self::endsOpen($word)), $bits);

            if ($last && $mode === 'suffix') {
                // The suffix replaces the whole rhyme: "mar" + "c" + "us", not
                // "mar" + "co" + "us". Latin endings are what makes latin latin.
                $suffix = Weights::draw($rng, self::legal($f['final']['weights'], $word.$onset), $bits);
                $parts[] = $onset.$suffix;
                $word .= $onset.$suffix;

                continue;
            }

            $nucleus = Weights::draw($rng, self::legal($f['nucleus'], $word.$onset), $bits);
            $so_far = $word.$onset.$nucleus;

            $coda = match (true) {
                $last && $mode === 'open' => '',
                $last => Weights::draw($rng, self::legal($f['final']['weights'] ?? $f['coda'], $so_far), $bits),
                default => Weights::draw($rng, self::legal($f['coda'], $so_far), $bits),
            };

            $parts[] = $onset.$nucleus.$coda;
            $word = $so_far.$coda;
        }

        return [
            'word' => $word,
            'syllables' => $parts,
            'bits' => $bits,
        ];
    }

    /**
     * The options a table may legally offer after the letters written so far.
     *
     * Three rules, each earned by output that was visibly wrong without it:
     *
     *  - no doubled letter across a join, or "fj" + "ja" becomes *fjja*;
     *  - no vowel meeting a vowel, or "ee" + "el" becomes *eeel*, an unreadable run;
     *  - no consonant run past three, or "nd" + "thr" becomes *ndthr*, which is not
     *    a cluster any language would let you say.
     *
     * Filtering before the draw rather than rejecting after it matters for more
     * than tidiness: a rejected draw would have to be redrawn from the same table,
     * which quietly re-weights it, and the surprisal reported as entropy would
     * then describe a distribution the generator is not actually using.
     *
     * @param  array<string, int|float>  $table
     * @return array<string, int|float>
     */
    private static function legal(array $table, string $left, bool $allowEmpty = true): array
    {
        $legal = [];

        foreach ($table as $option => $weight) {
            $option = (string) $option;

            if ($option === '') {
                if ($allowEmpty) {
                    $legal[$option] = $weight;
                }

                continue;
            }

            if ($left === '') {
                $legal[$option] = $weight;

                continue;
            }

            $tail = mb_substr($left, -1);
            $head = mb_substr($option, 0, 1);

            if ($tail === $head) {
                continue;
            }

            if (self::isVowel($tail) && self::isVowel($head)) {
                continue;
            }

            $closing = self::consonantRun($left, true);
            $opening = self::consonantRun($option, false);

            if ($closing + $opening > self::MAX_CLUSTER) {
                continue;
            }

            // A two-consonant coda meeting any onset at all is the other half of the
            // rule: "st" + "r" and "th" + "s" both pass the length check and neither
            // is sayable. A cluster may be built up on one side of a join or the
            // other, never on both.
            if ($closing > 1 && $opening > 0) {
                continue;
            }

            $legal[$option] = $weight;
        }

        // An empty table means the flavour has painted itself into a corner — rare,
        // and a slightly awkward word is a better answer on a live page than an
        // exception from a generator whose entire job is making words up.
        return $legal === [] ? $table : $legal;
    }

    /** Consonants in a row at one end of a string, which is what a cluster is. */
    private static function consonantRun(string $text, bool $fromEnd): int
    {
        $letters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($fromEnd) {
            $letters = array_reverse($letters);
        }

        $run = 0;
        foreach ($letters as $letter) {
            if (self::isVowel($letter)) {
                break;
            }
            $run++;
        }

        return $run;
    }

    private static function endsOpen(string $word): bool
    {
        return $word !== '' && self::isVowel(mb_substr($word, -1));
    }

    /** Unicode-safe, because the nordic tables carry ø and å. */
    public static function capitalise(string $word): string
    {
        return mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
    }

    private static function isVowel(string $char): bool
    {
        return $char !== '' && mb_strpos(self::VOWELS, mb_strtolower($char)) !== false;
    }

    /** @return array<string, mixed> */
    private static function flavour(string $key): array
    {
        $flavours = self::flavours();

        // An unknown flavour means a stale permalink or a hand-edited request, and
        // the first flavour is a better answer than a 500 on a page that is only
        // ever showing made-up words anyway.
        return $flavours[$key] ?? reset($flavours);
    }

    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = dirname(__DIR__, 4).'/resources/data/syllables.json';

        return self::$data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
