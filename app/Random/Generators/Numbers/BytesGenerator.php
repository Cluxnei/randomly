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
 * The entropy itself, unformatted — docs/04 §3.
 *
 * Every other generator in this module turns the stream into something: a number
 * in a range, a note in a scale, a point on a sphere. This one turns it into
 * nothing at all. What comes out is the HKDF stream as it was read, which makes
 * it the only page on the site where the thing the receipt describes is visible
 * rather than inferred.
 *
 * **Marked sensitive, for the same reason numbers.password is.** Raw bytes from a
 * public, replayable seed are not raw bytes — they are a key anyone holding the
 * link can regenerate. Somebody will paste this into an AES key, a JWT secret or
 * a session salt, because that is exactly what a "random bytes" tool is for, and
 * the studio withholding the permalink is what keeps that from being a disaster.
 * The generated material is also a monoalphabetic function of the stream, so a
 * single leaked output *is* the stream — everything derived from that seed, for
 * every generator, follows from it.
 */
final class BytesGenerator extends BaseGenerator
{
    public const ENCODINGS = [
        'hex' => 'Hexadecimal',
        'base64' => 'Base64',
        'base64url' => 'Base64 (URL-safe)',
        'binary' => 'Binary (bits)',
        'decimal' => 'Decimal (0–255)',
        'c_array' => 'C array',
    ];

    public function key(): string
    {
        return 'numbers.bytes';
    }

    public function name(): string
    {
        return 'Raw Bytes';
    }

    public function tagline(): string
    {
        return 'The entropy itself, in hex, base64 or binary.';
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
            ->int('count', 'How many bytes', default: 32, min: 1, max: 1024, help: '16 bytes is an AES-128 key, 32 an AES-256 key. Both are more randomness than anything you own needs.')
            ->enum('encoding', 'Encoding', self::ENCODINGS, default: 'hex')
            ->bool('uppercase', 'Uppercase hex')
            ->int('group', 'Group every', default: 0, min: 0, max: 16, help: 'Insert a space every n bytes. Zero is one unbroken string, which is what you want if you are about to paste it.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('count');

        // One read rather than $count reads. The stream is a stream — the bytes
        // are identical either way — but a single read is one HMAC chain walk
        // instead of a thousand method calls, and it makes stream_bytes_used in
        // the meta read exactly as the number the user asked for.
        $bytes = $rng->bytes($count);

        $encoding = $params->string('encoding', 'hex');
        $encoded = $this->encode($bytes, $encoding, $params->bool('uppercase'), $params->int('group'));

        return new Result(
            value: [
                'encoding' => $encoding,
                'bytes' => $count,
                'encoded' => $encoded,
            ],
            display: $encoded,
            meta: [
                'bytes' => $count,
                'entropy_out_bits' => $count * 8,
                'distinct_byte_values' => count(array_unique(array_map('ord', str_split($bytes)))),
                // The sample entropy of what was actually produced, which for a
                // short draw is *always* below 8 bits per byte — 32 bytes cannot
                // cover 256 values, so a third of them are missing by arithmetic
                // rather than by any failure of the stream. Printed because
                // somebody will compute it and wonder.
                'measured_bits_per_byte' => round($this->shannon($bytes), 3),
                'sensitive' => true,
                'note' => 'This is the stream itself, not something derived from it — which is why there is no permalink. A shareable link to key material would let anyone holding it regenerate the key, and everything else that seed ever produced with it.',
            ],
        );
    }

    private function encode(string $bytes, string $encoding, bool $uppercase, int $group): string
    {
        $encoded = match ($encoding) {
            // Base64 is grouped by its own 4-character blocks rather than by
            // bytes: three bytes become four characters, so a "every 4 bytes"
            // break would land mid-character and make the string unpasteable.
            'base64' => base64_encode($bytes),
            'base64url' => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='),
            'binary' => implode('', array_map(
                fn (string $b): string => str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT),
                str_split($bytes),
            )),
            'decimal' => implode(' ', array_map('ord', str_split($bytes))),
            'c_array' => '{ '.implode(', ', array_map(
                fn (string $b): string => '0x'.str_pad(dechex(ord($b)), 2, '0', STR_PAD_LEFT),
                str_split($bytes),
            )).' }',
            default => bin2hex($bytes),
        };

        if ($encoding === 'hex' && $uppercase) {
            $encoded = strtoupper($encoded);
        }

        if ($group <= 0 || in_array($encoding, ['base64', 'base64url', 'c_array'], true)) {
            return $encoded;
        }

        return $this->grouped($encoded, $encoding, $group);
    }

    /** Break the encoded string every `group` *bytes*, which is a different number of characters per encoding. */
    private function grouped(string $encoded, string $encoding, int $group): string
    {
        $charactersPerByte = match ($encoding) {
            'binary' => 8,
            'decimal' => 0, // already space-separated; regroup below
            default => 2,
        };

        if ($charactersPerByte === 0) {
            $parts = explode(' ', $encoded);

            return implode('  ', array_map(
                fn (array $chunk): string => implode(' ', $chunk),
                array_chunk($parts, $group),
            ));
        }

        return trim(chunk_split($encoded, $group * $charactersPerByte, ' '));
    }

    /**
     * Shannon entropy of the byte histogram, in bits per byte.
     *
     * A measurement of the output, not a claim about the source: 8.000 is
     * unreachable for anything shorter than a few kilobytes, and a low number
     * here means the sample is short, not that the stream is weak.
     */
    private function shannon(string $bytes): float
    {
        $counts = array_count_values(array_map('ord', str_split($bytes)));
        $length = strlen($bytes);
        $entropy = 0.0;

        foreach ($counts as $count) {
            $p = $count / $length;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }
}
