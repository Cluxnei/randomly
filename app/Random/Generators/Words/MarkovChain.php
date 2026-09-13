<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

use App\Random\Rng\Rng;
use Illuminate\Container\Container;

/**
 * An order-n character Markov chain, trained at runtime from a bundled wordlist.
 *
 * The table is `context => string of next characters, one entry per observation`.
 * Expanding the counts into a plain string rather than keeping a `char => count`
 * map buys two things: a draw becomes a single unbiased intBetween() into a
 * string instead of a float scan over a distribution, and the whole table
 * serialises to roughly the number of transitions in bytes, which is what makes
 * it cheap enough to cache.
 */
final class MarkovChain
{
    /** Pad character for the start context, and the end-of-word marker. */
    private const PAD = '^';

    private const END = '.';

    private const VOWELS = 'aeiou';

    /** @var array<string, array<string, string>> */
    private static array $memo = [];

    private function __construct(
        private readonly array $table,
        private readonly string $list,
        private readonly int $order,
    ) {}

    public static function for(string $list, int $order): self
    {
        $order = max(1, min(6, $order));
        $key = "randomly.markov.v1.{$list}.{$order}";

        if (! isset(self::$memo[$key])) {
            self::$memo[$key] = self::cached($key, fn (): array => self::train($list, $order));
        }

        return new self(self::$memo[$key], $list, $order);
    }

    /**
     * Grow one word, or null if this attempt ran off the rails.
     *
     * Returning null instead of repairing the word is deliberate. The two ways an
     * attempt fails are "the chain ended before min length" and "it never ended
     * before max length"; both are fixable by censoring the distribution — refuse
     * to draw the terminator, or force it — and both fixes silently bend the
     * chain away from the statistics it was trained on. Rejecting the whole
     * attempt and redrawing keeps the output faithful to the model, and the
     * caller counts the rejections so the cost is visible rather than hidden.
     */
    public function attempt(Rng $rng, int $min, int $max, ?float &$bits = null): ?string
    {
        $context = str_repeat(self::PAD, $this->order);
        $word = '';
        $bits = 0.0;

        while (true) {
            $choices = $this->table[$context] ?? null;

            // Only reachable if the table was trained on a different corpus than
            // the one being walked; a missing context is a bug, not bad luck.
            if ($choices === null) {
                return null;
            }

            $size = strlen($choices);
            $next = $choices[$rng->intBetween(0, $size - 1)];

            // Surprisal of this exact character: −log₂(count/total). Summed over a
            // word it is how many bits an attacker would have to guess, which is
            // the only entropy claim this generator can honestly make.
            $bits += -log(substr_count($choices, $next) / $size, 2);

            if ($next === self::END) {
                return strlen($word) >= $min ? $word : null;
            }

            $word .= $next;

            if (strlen($word) >= $max) {
                return null;
            }

            $context = substr($context.$next, -$this->order);
        }
    }

    public function isRealWord(string $word): bool
    {
        return Corpus::contains($this->list, $word);
    }

    public static function hasVowel(string $word): bool
    {
        return strpbrk($word, self::VOWELS) !== false;
    }

    /**
     * Count transitions per context over the corpus.
     *
     * Words are padded with n start markers and closed with a terminator, so the
     * chain learns where words like to begin and end rather than only how their
     * middles run — without the terminator it would have no notion of a word being
     * finished and every candidate would hit the length cap.
     */
    private static function train(string $list, int $order): array
    {
        $table = [];

        foreach (Corpus::words($list) as $word) {
            // Four of the 7,776 EFF entries are hyphenated ("yo-yo", "t-shirt").
            // Training on them would put a hyphen in the alphabet for the sake of
            // 0.05% of the corpus, and an invented word with a hyphen in it reads
            // as a bug rather than as a word.
            if (preg_match('/^[a-z]+$/', $word) !== 1) {
                continue;
            }

            $padded = str_repeat(self::PAD, $order).$word.self::END;

            for ($i = 0; $i + $order < strlen($padded); $i++) {
                $context = substr($padded, $i, $order);
                $table[$context] = ($table[$context] ?? '').$padded[$i + $order];
            }
        }

        return $table;
    }

    /**
     * Hold the trained table across requests when there is a cache to hold it in.
     *
     * Training walks every character of 7,776 words, which is tens of milliseconds
     * thrown away on every page load otherwise. Guarded rather than assumed: unit
     * tests in this project never boot the framework — that is what keeps the suite
     * instant — so there is no container to ask, and the static memo above carries
     * them instead.
     */
    private static function cached(string $key, \Closure $build): array
    {
        try {
            $container = Container::getInstance();

            if (! $container->bound('cache')) {
                return $build();
            }

            return $container->make('cache')->remember($key, 86_400, $build);
        } catch (\Throwable) {
            // A misconfigured cache store must not take down a word generator that
            // works perfectly well without one.
            return $build();
        }
    }
}
