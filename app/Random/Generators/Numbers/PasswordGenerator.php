<?php

declare(strict_types=1);

namespace App\Random\Generators\Numbers;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * The generator that proves the sensitivity rule is real.
 *
 * Everything else here is built to be shared: a seed, a permalink, a receipt
 * anyone can verify. A password is the exact opposite, so this one declares
 * isSensitive() and the studio withholds the permalink entirely.
 */
final class PasswordGenerator extends BaseGenerator
{
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const DIGITS = '0123456789';

    private const SYMBOLS = '!@#$%^&*()-_=+[]{};:,.?/';

    /** Characters that a human transcribing from a screen reliably gets wrong. */
    private const AMBIGUOUS = 'Il1O0o';

    public function key(): string
    {
        return 'numbers.password';
    }

    public function name(): string
    {
        return 'Passwords';
    }

    public function tagline(): string
    {
        return 'Random characters, with the entropy stated in bits instead of a green bar.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function isSensitive(): bool
    {
        return true;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('length', 'Length', default: 20, min: 6, max: 128)
            ->int('count', 'How many', default: 5, min: 1, max: 50)
            ->bool('lowercase', 'Lowercase', default: true)
            ->bool('uppercase', 'Uppercase', default: true)
            ->bool('digits', 'Digits', default: true)
            ->bool('symbols', 'Symbols', default: true)
            ->bool('avoid_ambiguous', 'Avoid lookalikes', help: 'Drops I l 1 O 0 o, which get mistyped from a screen.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $alphabet = $this->alphabet($params);
        $length = $params->int('length');
        $size = strlen($alphabet);

        $passwords = [];
        for ($i = 0; $i < $params->int('count'); $i++) {
            $password = '';
            for ($j = 0; $j < $length; $j++) {
                $password .= $alphabet[$rng->intBetween(0, $size - 1)];
            }
            $passwords[] = $password;
        }

        // H = L · log2(A). This is the honest figure: it assumes the attacker knows
        // the alphabet and the length, which they do, because it is on this page.
        $bits = $length * log($size, 2);

        return new Result(
            value: $passwords,
            display: implode(PHP_EOL, $passwords),
            meta: [
                'alphabet_size' => $size,
                'entropy_out_bits' => round($bits, 1),
                'crack_time' => $this->crackTime($bits),
                'crack_assumption' => '10¹¹ guesses/second — a GPU rig against a fast hash.',
            ],
        );
    }

    private function alphabet(Params $params): string
    {
        $alphabet = '';
        $alphabet .= $params->bool('lowercase') ? self::LOWER : '';
        $alphabet .= $params->bool('uppercase') ? self::UPPER : '';
        $alphabet .= $params->bool('digits') ? self::DIGITS : '';
        $alphabet .= $params->bool('symbols') ? self::SYMBOLS : '';

        // Every set switched off is a user mid-decision, not an error state.
        if ($alphabet === '') {
            $alphabet = self::LOWER;
        }

        if ($params->bool('avoid_ambiguous')) {
            $alphabet = str_replace(str_split(self::AMBIGUOUS), '', $alphabet);
        }

        return $alphabet;
    }

    /**
     * Expected time to a hit is half the keyspace, so 2^(H−1) guesses.
     *
     * Stated as a duration with the guess rate written next to it, because
     * "strong" on its own tells nobody anything.
     */
    private function crackTime(float $bits): string
    {
        $seconds = (2 ** ($bits - 1)) / 1e11;
        $years = $seconds / 31_557_600;

        // Past a few billion years the number stops being a duration anyone can
        // hold, so we switch to scientific notation and anchor it to something
        // real. "10^22 years" means nothing on its own; next to the age of the
        // universe it means everything.
        if ($years >= 1e10) {
            $exponent = (int) floor(log10($years));
            $mantissa = round($years / (10 ** $exponent), 1);

            return sprintf(
                '%s × 10^%d years — the universe is 1.4 × 10^10 years old',
                $mantissa,
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
