<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

/**
 * Bits of entropy expressed as time, with the assumption written next to it.
 *
 * Deliberately duplicated from the password generator rather than hoisted into a
 * shared helper both would import: the two are separate modules, and the honest
 * version of this number is the one stated alongside its guess rate. If the rate
 * ever needs to differ between a passphrase and a password, a shared constant
 * would be the thing standing in the way.
 */
final class CrackTime
{
    /** A GPU rig against a fast hash. Stated on screen; never implied. */
    public const RATE = 1e11;

    public const ASSUMPTION = '10¹¹ guesses/second — a GPU rig against a fast hash.';

    /** Expected time to a hit is half the keyspace, hence 2^(H−1) guesses. */
    public static function describe(float $bits): string
    {
        $seconds = (2 ** ($bits - 1)) / self::RATE;
        $years = $seconds / 31_557_600;

        // Past a few billion years the number stops being a duration anyone can
        // hold in their head, so it switches to scientific notation anchored to
        // something real. "10^22 years" means nothing on its own.
        if ($years >= 1e10) {
            $exponent = (int) floor(log10($years));

            return sprintf(
                '%s × 10^%d years — the universe is 1.4 × 10^10 years old',
                round($years / (10 ** $exponent), 1),
                $exponent,
            );
        }

        return match (true) {
            $seconds < 1 => 'instantly',
            $seconds < 60 => round($seconds).' seconds',
            $seconds < 3600 => round($seconds / 60).' minutes',
            $seconds < 86400 => round($seconds / 3600).' hours',
            $seconds < 31_557_600 => round($seconds / 86400).' days',
            $years < 1e3 => round($years).' years',
            $years < 1e6 => round($years / 1e3, 1).' thousand years',
            $years < 1e9 => round($years / 1e6, 1).' million years',
            default => round($years / 1e9, 1).' billion years',
        };
    }
}
