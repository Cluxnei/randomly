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
 * UUID v4, UUID v7, ULID and NanoID — four answers to "give me an identifier".
 *
 * Marked sensitive, and the reason is the same one that keeps a permalink away
 * from numbers.password. An identifier here is derived from a public, replayable
 * seed: hand someone the link and you have handed them every session token,
 * primary key and idempotency key on the page. Identifiers built this way are
 * fine for filling a fixture file and are not fine for anything that has to be
 * unguessable, so the link is simply not offered.
 *
 * The generator also has no clock, because Generator::generate() may not have
 * one — the same seed has to produce the same output in a year's time. The moment
 * that goes into a v7 or a ULID is therefore a *parameter*, which turns out to be
 * the better teaching tool anyway: type a date, watch exactly which characters
 * change, and the thing v7 leaks stops being an abstraction.
 */
final class UuidGenerator extends BaseGenerator
{
    /** Crockford base32, the ULID alphabet: no I, L, O or U, so it survives being read aloud. */
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * NanoID's alphabet is 64 symbols, and that is the only part that matters:
     * 64 is exactly 2^6, so every 6 bits of stream yield one character with no
     * rejection and no modulo bias. The reference implementation ships a
     * scrambled-looking string of the same 64 URL-safe symbols; the order changes
     * nothing about the entropy, so this uses the readable arrangement.
     */
    private const NANO = '-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';

    /** 2026-01-01T00:00:00Z, in milliseconds. The fallback when the date field is mid-edit. */
    private const DEFAULT_MOMENT_MS = 1_767_225_600_000;

    public function key(): string
    {
        return 'numbers.uuid';
    }

    public function name(): string
    {
        return 'Identifiers';
    }

    public function tagline(): string
    {
        return 'UUID v4 and v7, ULID, NanoID — and what each one gives away.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    /**
     * Never replayable. See the class comment: a public seed makes every
     * identifier on the page guessable by anyone holding the link.
     */
    public function isSensitive(): bool
    {
        return true;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('flavour', 'Flavour', [
                'v4' => 'UUID v4 — 122 random bits',
                'v7' => 'UUID v7 — time-ordered',
                'ulid' => 'ULID — time-ordered, base32',
                'nanoid' => 'NanoID — short and URL-safe',
            ], default: 'v4')
            ->int('count', 'How many', default: 8, min: 1, max: 200)
            ->string('moment', 'Clock (v7 and ULID)', default: '2026-01-01T00:00:00Z', max: 32, help: 'These two carry a timestamp. It is a field rather than the real clock so the same seed keeps producing the same identifiers.')
            ->int('spread', 'Milliseconds between them', default: 250, min: 1, max: 60_000, help: 'Successive identifiers advance the clock by a random amount up to this, which is what makes the ordering visible.')
            ->int('size', 'NanoID length', default: 21, min: 8, max: 64)
            ->bool('uppercase', 'Uppercase hex', help: 'RFC 9562 says to emit lowercase and to accept either.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $flavour = $params->string('flavour', 'v4');
        $count = $params->int('count');
        $ms = $this->momentMs($params->string('moment'));
        $spread = $params->int('spread');

        $ids = [];
        $clock = $ms;

        for ($i = 0; $i < $count; $i++) {
            // At least one millisecond between identifiers, so the time ordering
            // is strict and a sort really does recover the generation order. Two
            // real v7s inside the same millisecond fall back to their random bits
            // and are only *weakly* ordered, which is a footnote this generator
            // would rather not have to draw.
            if ($i > 0) {
                $clock += $rng->intBetween(1, $spread);
            }

            $ids[] = match ($flavour) {
                'v7' => $this->uuidV7($rng, $clock),
                'ulid' => $this->ulid($rng, $clock),
                'nanoid' => $this->nanoId($rng, $params->int('size')),
                default => $this->uuidV4($rng),
            };
        }

        if ($params->bool('uppercase') && ($flavour === 'v4' || $flavour === 'v7')) {
            $ids = array_map(strtoupper(...), $ids);
        }

        return new Result(
            value: $ids,
            display: implode("\n", $ids),
            meta: $this->explain($flavour, $params, $ms, $clock, $ids),
        );
    }

    /**
     * RFC 9562 §5.4. Sixteen random bytes with six of them overwritten: four bits
     * of version and two of variant, which is why a v4 carries 122 bits of
     * randomness rather than 128.
     */
    private function uuidV4(Rng $rng): string
    {
        $bytes = $rng->bytes(16);

        return $this->format($this->stamp($bytes, 4));
    }

    /**
     * RFC 9562 §5.7. The first 48 bits are the Unix timestamp in milliseconds,
     * big-endian, so the identifier sorts by time as a string *and* as a number —
     * which is the entire reason it exists: a v4 primary key scatters inserts
     * across a B-tree, and a v7 appends to the end of it.
     *
     * The price is that the first six bytes are not random. Anyone holding the id
     * knows when the row was written, to the millisecond.
     */
    private function uuidV7(Rng $rng, int $ms): string
    {
        // 48 bits of big-endian timestamp. pack('J') gives eight bytes; the two
        // most significant are always zero until the year 10889, and dropping them
        // is what leaves the 48 the format asks for.
        $bytes = substr(pack('J', $ms), 2, 6).$rng->bytes(10);

        return $this->format($this->stamp($bytes, 7));
    }

    /** Write the version nibble into byte 6 and the RFC 4122 variant bits (10xx) into byte 8. */
    private function stamp(string $bytes, int $version): string
    {
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | ($version << 4));
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return $bytes;
    }

    private function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    /**
     * A ULID: 48 bits of timestamp then 80 bits of randomness, the whole 128
     * written as 26 Crockford base32 characters.
     *
     * Encoded as two separate runs rather than one, because the padding goes at
     * the *front*. Twenty-six characters carry 130 bits, so the leading character
     * uses only three of its five; padding at the tail instead — which is what a
     * general-purpose base32 encoder does — shifts every character and produces
     * something that is not a ULID.
     */
    private function ulid(Rng $rng, int $ms): string
    {
        return $this->crockford(str_pad(decbin($ms), 48, '0', STR_PAD_LEFT), 10)
            .$this->crockford($this->bits($rng->bytes(10)), 16);
    }

    private function bits(string $bytes): string
    {
        $out = '';
        foreach (str_split($bytes) as $byte) {
            $out .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    private function crockford(string $bits, int $characters): string
    {
        $bits = str_pad($bits, $characters * 5, '0', STR_PAD_LEFT);
        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::CROCKFORD[bindec($chunk)];
        }

        return $out;
    }

    private function nanoId(Rng $rng, int $length): string
    {
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::NANO[$rng->intBetween(0, 63)];
        }

        return $out;
    }

    /**
     * Parse the date field without ever consulting a clock.
     *
     * strtotime() would be one line and would also accept "now", which is exactly
     * the impurity this generator is not allowed to have: a seed that reproduces
     * today and not tomorrow is worse than no reproducibility at all. So the
     * format is fixed, and anything that does not match falls back to a constant.
     */
    private function momentMs(string $moment): int
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3}))?)?)?Z?$/', trim($moment), $m)) {
            return self::DEFAULT_MOMENT_MS;
        }

        // Ranges checked rather than left to gmmktime, which happily rolls month
        // 13 into the following January. Silently accepting a date nobody typed is
        // how a field ends up reporting a moment the user never asked for.
        if ((int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[3] < 1 || (int) $m[3] > 31
            || (int) ($m[4] ?? 0) > 23 || (int) ($m[5] ?? 0) > 59 || (int) ($m[6] ?? 0) > 60) {
            return self::DEFAULT_MOMENT_MS;
        }

        $seconds = gmmktime(
            (int) ($m[4] ?? 0),
            (int) ($m[5] ?? 0),
            (int) ($m[6] ?? 0),
            (int) $m[2],
            (int) $m[3],
            (int) $m[1],
        );

        if ($seconds === false) {
            return self::DEFAULT_MOMENT_MS;
        }

        return $seconds * 1000 + (int) str_pad($m[7] ?? '0', 3, '0');
    }

    private function explain(string $flavour, Params $params, int $first, int $last, array $ids): array
    {
        $bits = match ($flavour) {
            'v7' => 74.0,
            'ulid' => 80.0,
            'nanoid' => $params->int('size') * 6.0,
            default => 122.0,
        };

        $meta = [
            'flavour' => $flavour,
            'random_bits_each' => $bits,
            'entropy_out_bits' => round($bits * count($ids), 2),
            // The birthday bound: a 50% chance of a collision arrives at about
            // 1.177·√(2^bits) identifiers. It is the number people actually want
            // when they ask "is 21 characters enough".
            'collision_at_50_percent' => $this->approximate(1.1774 * (2 ** ($bits / 2))),
        ];

        if ($flavour === 'v7' || $flavour === 'ulid') {
            $prefix = $flavour === 'v7' ? substr($ids[0], 0, 13) : substr($ids[0], 0, 10);

            $meta['timestamp_prefix'] = $prefix;
            $meta['first_moment'] = gmdate('Y-m-d\TH:i:s', intdiv($first, 1000)).sprintf('.%03dZ', $first % 1000);
            $meta['last_moment'] = gmdate('Y-m-d\TH:i:s', intdiv($last, 1000)).sprintf('.%03dZ', $last % 1000);
            $meta['note'] = sprintf(
                'The leading %s — %s — is the clock, not randomness: 48 of the 128 bits are the millisecond it was made. That is what makes these sort by time, and it is also what they leak. Anyone holding one knows when the row was written; hold two and you know how long the gap between them was.',
                $flavour === 'v7' ? 'six bytes' : 'ten characters',
                $prefix,
            );
        } else {
            $meta['note'] = $flavour === 'nanoid'
                ? sprintf('%d characters from a 64-symbol alphabet: exactly 6 bits each, so there is no rejection and no modulo bias. %.0f bits in %d characters, against a UUID v4\'s 122 bits in 36.', $params->int('size'), $bits, $params->int('size'))
                : 'Sixteen random bytes with six overwritten — four bits of version, two of variant — which is why a v4 carries 122 bits and not 128. Nothing in it is ordered, which is exactly why a table keyed on one inserts all over its own index.';
        }

        return $meta;
    }

    /** "1.2 × 10^18" — the only readable form for a number with nineteen digits. */
    private function approximate(float $n): string
    {
        if ($n < 1e6) {
            return number_format($n);
        }

        $exponent = (int) floor(log10($n));

        return sprintf('%.1f × 10^%d', $n / (10 ** $exponent), $exponent);
    }
}
