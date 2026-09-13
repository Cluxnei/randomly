<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * The NIST Randomness Beacon: a 512-bit value published every 60 seconds,
 * hash-chained to every pulse before it and signed by NIST.
 *
 * Class B, and the reason that class exists. The signature makes it provably
 * fresh; publication makes it provably public. Wonderful for a receipt, useless
 * on its own for a secret.
 */
final class NistBeaconSource implements EntropySource
{
    public function key(): string
    {
        return 'nist-beacon';
    }

    public function label(): string
    {
        return 'NIST Randomness Beacon';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Public;
    }

    public function origin(): string
    {
        return 'A hardware entropy source at the US National Institute of Standards and Technology, hash-chained and signed every minute.';
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function collect(int $bytes): Material
    {
        $pulse = Http::timeout(1.5)
            ->get('https://beacon.nist.gov/beacon/2.0/pulse/last')
            ->throw()
            ->json('pulse');

        $raw = @hex2bin($pulse['outputValue'] ?? '');

        if ($raw === false || strlen($raw) < $bytes) {
            throw new \RuntimeException('NIST beacon returned an unusable pulse.');
        }

        $index = (int) $pulse['pulseIndex'];

        return new Material(
            bytes: substr($raw, 0, $bytes),
            narrative: sprintf('NIST Beacon pulse #%s — chained to every pulse before it, and signed.', number_format($index)),
            proofUrl: $pulse['uri'] ?? null,
            reference: (string) $index,
            observedAt: new \DateTimeImmutable($pulse['timeStamp']),
        );
    }
}
