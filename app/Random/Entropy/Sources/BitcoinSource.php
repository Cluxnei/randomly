<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * The hash of the most recent Bitcoin block.
 *
 * Every leading zero in that hash is proof of work nobody could shortcut: the
 * whole network grinds nonces for roughly ten minutes to find one. It is the most
 * expensive random number humanity produces, and it is free to read.
 *
 * Class B, emphatically. The hash is published the instant it exists and every
 * node on earth has a copy, so it is unpredictable *before* the block is mined
 * and public knowledge one second later.
 */
final class BitcoinSource implements EntropySource
{
    public function key(): string
    {
        return 'bitcoin';
    }

    public function label(): string
    {
        return 'Bitcoin Proof-of-Work';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Public;
    }

    public function origin(): string
    {
        return 'The block header hash the entire Bitcoin network spent roughly ten minutes of global hashrate searching for.';
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function collect(int $bytes): Material
    {
        $hash = trim(Http::timeout(1.5)->get('https://mempool.space/api/blocks/tip/hash')->throw()->body());

        if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new \RuntimeException('mempool.space returned something that is not a block hash.');
        }

        $height = (int) trim(Http::timeout(1.5)->get('https://mempool.space/api/blocks/tip/height')->throw()->body());

        // The leading zeros are the proof — and they are also the least useful
        // bytes in the hash, being identical in every block. Read from the end,
        // where the bits are actually unpredictable.
        $raw = strrev((string) hex2bin($hash));
        $zeros = strlen($hash) - strlen(ltrim($hash, '0'));

        return new Material(
            bytes: substr($raw, 0, $bytes),
            narrative: sprintf(
                'Bitcoin block %s — %d leading zeros of proof-of-work, found about %s ago.',
                number_format($height),
                $zeros,
                'ten minutes',
            ),
            proofUrl: "https://mempool.space/block/{$hash}",
            reference: (string) $height,
            observedAt: new \DateTimeImmutable,
        );
    }
}
