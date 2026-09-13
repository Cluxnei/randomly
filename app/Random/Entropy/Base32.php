<?php

declare(strict_types=1);

namespace App\Random\Entropy;

/**
 * Crockford base32 — no I, L, O or U, so tokens survive being read aloud,
 * handwritten, or typed from a screenshot.
 */
final class Base32
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    public static function decode(string $token): string
    {
        // Crockford's forgiving aliases: O reads as 0, I and L read as 1.
        $token = strtr(strtoupper(trim($token)), ['O' => '0', 'I' => '1', 'L' => '1', '-' => '']);

        $bits = '';
        foreach (str_split($token) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                throw new \InvalidArgumentException("Not a valid token character: {$char}");
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $chunk) {
            $out .= chr(bindec($chunk));
        }

        return $out;
    }
}
