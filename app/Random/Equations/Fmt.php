<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * Printing numbers the way a person would write them.
 *
 * A generated coefficient is nearly always an integer or a small fraction, and
 * a worksheet that says `0.6666666666666666` where it means `2/3` is a worksheet
 * nobody hands out. So the rule is: integers print as integers, anything with a
 * small denominator prints as a fraction, and only a genuinely irrational value
 * falls through to decimals.
 */
final class Fmt
{
    /**
     * The largest denominator worth showing as a fraction.
     *
     * Past this a fraction stops being easier to read than the decimal, and the
     * scan below stops being cheap.
     */
    private const MAX_DENOMINATOR = 64;

    /** Floats that came out of arithmetic are never exactly equal to their rational. */
    private const EPSILON = 1e-9;

    public static function plain(float $value): string
    {
        if (self::isInteger($value)) {
            return (string) (int) round($value);
        }

        $fraction = self::asFraction($value);

        if ($fraction !== null) {
            [$numerator, $denominator] = $fraction;

            return "{$numerator}/{$denominator}";
        }

        return self::decimal($value);
    }

    public static function latex(float $value): string
    {
        if (self::isInteger($value)) {
            return (string) (int) round($value);
        }

        $fraction = self::asFraction($value);

        if ($fraction !== null) {
            [$numerator, $denominator] = $fraction;
            $sign = $numerator < 0 ? '-' : '';

            return $sign.'\frac{'.abs($numerator).'}{'.$denominator.'}';
        }

        return self::decimal($value);
    }

    public static function isInteger(float $value): bool
    {
        return is_finite($value) && abs($value - round($value)) < self::EPSILON && abs($value) < 1e15;
    }

    /**
     * @return array{int, int}|null numerator and positive denominator, in lowest terms
     */
    public static function asFraction(float $value): ?array
    {
        if (! is_finite($value) || abs($value) > 1e9) {
            return null;
        }

        for ($denominator = 2; $denominator <= self::MAX_DENOMINATOR; $denominator++) {
            $numerator = $value * $denominator;

            if (abs($numerator - round($numerator)) < self::EPSILON) {
                $n = (int) round($numerator);
                $divisor = self::gcd(abs($n), $denominator);

                return [intdiv($n, $divisor), intdiv($denominator, $divisor)];
            }
        }

        return null;
    }

    /** Six significant figures, with the trailing zeros PHP leaves behind trimmed off. */
    public static function decimal(float $value): string
    {
        if (! is_finite($value)) {
            return is_nan($value) ? 'undefined' : ($value > 0 ? '∞' : '-∞');
        }

        $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    public static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }

    /**
     * Split n into k²·m with m square-free, so a surd can be written √50 = 5√2.
     *
     * @return array{int, int} the extracted factor k and the square-free remainder m
     */
    public static function simplifySurd(int $n): array
    {
        $outside = 1;
        $inside = abs($n);

        for ($factor = 2; $factor * $factor <= $inside; $factor++) {
            while ($inside % ($factor * $factor) === 0) {
                $inside = intdiv($inside, $factor * $factor);
                $outside *= $factor;
            }
        }

        return [$outside, $inside];
    }

    public static function isPerfectSquare(int $n): bool
    {
        if ($n < 0) {
            return false;
        }

        $root = (int) round(sqrt($n));

        return $root * $root === $n;
    }
}
