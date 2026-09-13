<?php

declare(strict_types=1);

namespace App\Random\Rng;

/**
 * An endless, deterministic byte stream: HKDF-Expand (RFC 5869) in counter mode.
 *
 *     T(0) = ""
 *     T(i) = HMAC-SHA256(PRK, T(i-1) ‖ info ‖ LE32(i))
 *
 * One deliberate deviation from RFC 5869: the counter is 32 bits rather than 8.
 * The RFC caps output at 255 × 32 bytes, which a 4-megapixel noise field burns
 * through immediately. Widening the counter keeps every other property intact and
 * is documented here so the JS twin in resources/js/rng.js can match it exactly.
 */
final class HkdfStream
{
    private string $buffer = '';

    private string $block = '';

    private int $counter = 0;

    private int $bytesRead = 0;

    public function __construct(
        private readonly string $prk,
        private readonly string $info,
    ) {}

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Cannot read a negative number of bytes.');
        }

        while (strlen($this->buffer) < $length) {
            $this->counter++;
            $this->block = hash_hmac(
                'sha256',
                $this->block.$this->info.pack('V', $this->counter),
                $this->prk,
                true,
            );
            $this->buffer .= $this->block;
        }

        $out = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        $this->bytesRead += $length;

        return $out;
    }

    /** How much entropy this generation actually consumed — shown in the UI. */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }
}
