<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

/**
 * The bundled EFF wordlists, parsed once and held.
 *
 * The files are the EFF's own `<five-digit dice roll>\t<word>` format and the
 * rolls are kept rather than stripped: showing which five dice produced a word is
 * the clearest illustration of where 12.925 bits come from.
 *
 * Parsing 7,776 lines costs a couple of milliseconds, which is cheap but entirely
 * repeated work — every generator in this module reads the same two files, so the
 * parse is memoised per process. No Laravel cache here on purpose: unserialising
 * an 8k-element array is not obviously faster than re-reading the file, and the
 * static memo already covers the case that matters (several generators, or
 * several Markov orders, inside one request).
 */
final class Corpus
{
    public const LARGE = 'large';

    public const SHORT = 'short';

    private const FILES = [
        self::LARGE => 'eff_large_wordlist.txt',
        self::SHORT => 'eff_short_wordlist_1.txt',
    ];

    /** @var array<string, array{rolls: list<string>, words: list<string>, index: array<string, true>}> */
    private static array $memo = [];

    /** @return array<string, string> list key => label, for a generator's enum schema */
    public static function options(): array
    {
        return [
            self::LARGE => 'EFF large — 7,776 words (12.9 bits each)',
            self::SHORT => 'EFF short — 1,296 words (10.3 bits each)',
        ];
    }

    /** @return list<string> */
    public static function words(string $list): array
    {
        return self::load($list)['words'];
    }

    /** @return list<string> the five-digit dice roll for each word, in the same order */
    public static function rolls(string $list): array
    {
        return self::load($list)['rolls'];
    }

    /** @return array{roll: string, word: string} */
    public static function entry(string $list, int $index): array
    {
        $data = self::load($list);

        return ['roll' => $data['rolls'][$index], 'word' => $data['words'][$index]];
    }

    public static function size(string $list): int
    {
        return count(self::load($list)['words']);
    }

    /**
     * Is this a word someone else already invented?
     *
     * O(1) against a flipped array rather than in_array over 7,776 strings, because
     * the Markov generator asks this on every single candidate it grows.
     */
    public static function contains(string $list, string $word): bool
    {
        return isset(self::load($list)['index'][$word]);
    }

    /** @return array{rolls: list<string>, words: list<string>, index: array<string, true>} */
    private static function load(string $list): array
    {
        $list = isset(self::FILES[$list]) ? $list : self::LARGE;

        if (isset(self::$memo[$list])) {
            return self::$memo[$list];
        }

        $path = dirname(__DIR__, 4).'/resources/data/'.self::FILES[$list];
        $rolls = [];
        $words = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            // Tab-separated by the EFF's format. A line without a tab would be a
            // corrupted bundle, and silently treating it as a word would poison the
            // entropy claim — the list size is the whole basis of log2(N).
            $parts = explode("\t", $line, 2);

            if (count($parts) !== 2) {
                throw new \RuntimeException("Malformed wordlist line in {$path}: {$line}");
            }

            $rolls[] = $parts[0];
            $words[] = $parts[1];
        }

        return self::$memo[$list] = [
            'rolls' => $rolls,
            'words' => $words,
            'index' => array_fill_keys($words, true),
        ];
    }
}
