<?php

declare(strict_types=1);

namespace App\Random\Audio;

/**
 * Bjorklund's algorithm — docs/09 §5.
 *
 * E(k,n) spreads k onsets over n steps as evenly as the integers allow. The
 * remarkable part, and the reason this generator exists, is that the answers are
 * not novel patterns: E(3,8) is the Cuban tresillo, E(7,12) is the West African
 * bell, E(2,5) is Korean. A rhythm that took a culture centuries to settle on
 * falls out of a greedy pairing algorithm in nine lines.
 *
 * The algorithm itself is Euclid's, run on the counts: pair each onset with a
 * rest, then pair the leftovers with the pairs, repeating until fewer than two
 * remainders are left. It is the same recursion as gcd(k, n−k).
 */
final class Euclid
{
    /**
     * The patterns docs/09 §5 names, so the UI can say what a draw landed on.
     *
     * Keyed "k/n" rather than offered as a preset that overrides the sliders:
     * recognition after the fact leaves the controls meaning what they say.
     */
    public const NAMED = [
        '3/8' => 'Cuban tresillo',
        '5/8' => 'Cuban cinquillo',
        '2/5' => 'Korean / Greek',
        '7/12' => 'West African bell',
        '9/16' => 'Brazilian samba',
        '5/16' => 'Bossa nova',
        '4/4' => 'Four on the floor',
        '7/16' => 'Brazilian samba (short)',
    ];

    /**
     * @return list<int> n steps, 1 for an onset and 0 for a rest
     */
    public static function pattern(int $k, int $n, int $rotation = 0): array
    {
        $n = max(1, $n);
        $k = max(0, min($k, $n));

        if ($k === 0) {
            return array_fill(0, $n, 0);
        }

        if ($k === $n) {
            return array_fill(0, $n, 1);
        }

        // Two piles of groups: k onsets and n−k rests. Each pass appends one
        // rest-group onto each onset-group, and whatever is left over becomes the
        // new rest pile. When one or zero remainders are left, no further
        // redistribution can make the spacing more even.
        $a = array_fill(0, $k, [1]);
        $b = array_fill(0, $n - $k, [0]);

        while (count($b) > 1) {
            $pairs = min(count($a), count($b));
            $merged = [];

            for ($i = 0; $i < $pairs; $i++) {
                $merged[] = [...$a[$i], ...$b[$i]];
            }

            $remainder = count($a) > $pairs
                ? array_slice($a, $pairs)
                : array_slice($b, $pairs);

            $a = $merged;
            $b = $remainder;
        }

        $flat = array_merge(...[...$a, ...$b]);

        return self::rotate($flat, $rotation);
    }

    /**
     * Rotate left: step r becomes step 0.
     *
     * Rotation is not a cosmetic control. E(5,8) rotated is what separates the
     * cinquillo from the son clave — same interval set, different downbeat, and
     * a listener hears two different rhythms.
     *
     * @param  list<int>  $pattern
     * @return list<int>
     */
    public static function rotate(array $pattern, int $rotation): array
    {
        $n = count($pattern);

        if ($n === 0 || $rotation === 0) {
            return $pattern;
        }

        $shift = (($rotation % $n) + $n) % $n;

        return array_merge(array_slice($pattern, $shift), array_slice($pattern, 0, $shift));
    }

    public static function name(int $k, int $n): ?string
    {
        return self::NAMED["{$k}/{$n}"] ?? null;
    }

    /** "x..x..x." — the notation docs/09 §5 writes these in. */
    public static function notation(array $pattern): string
    {
        return implode('', array_map(fn (int $step): string => $step === 1 ? 'x' : '.', $pattern));
    }
}
