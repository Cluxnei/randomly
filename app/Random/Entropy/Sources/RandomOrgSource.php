<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * Atmospheric radio noise, sampled by receivers tuned between stations.
 *
 * Free and keyless, but metered: a shared daily bit quota per IP. We read the
 * quota alongside the bytes so the UI can retire the source politely instead of
 * hammering it into a 503.
 */
final class RandomOrgSource implements EntropySource
{
    public function key(): string
    {
        return 'random-org';
    }

    public function label(): string
    {
        return 'RANDOM.ORG';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Cryptographic;
    }

    public function origin(): string
    {
        return 'Atmospheric radio noise, largely driven by lightning discharges, captured by receivers in Dublin.';
    }

    public function refreshSeconds(): int
    {
        return 0;
    }

    public function collect(int $bytes): Material
    {
        $hex = Http::timeout(1.5)
            ->retry(1, 100)
            ->get('https://www.random.org/cgi-bin/randbyte', [
                'nbytes' => $bytes,
                'format' => 'h',
            ])
            ->throw()
            ->body();

        $raw = @hex2bin(preg_replace('/[^0-9a-f]/i', '', $hex));

        if ($raw === false || strlen($raw) < $bytes) {
            throw new \RuntimeException('RANDOM.ORG returned malformed or short material.');
        }

        return new Material(
            bytes: $raw,
            narrative: 'Atmospheric radio noise, sampled in Dublin moments ago.',
            proofUrl: 'https://www.random.org/randomness/',
            observedAt: new \DateTimeImmutable,
        );
    }

    /** Remaining free bits for this IP today, or null when unreadable. */
    public function quotaBits(): ?int
    {
        try {
            $body = trim(Http::timeout(1.5)->get('https://www.random.org/quota/', ['format' => 'plain'])->body());

            return is_numeric($body) ? (int) $body : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
