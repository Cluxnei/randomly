<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * drand, run by the League of Entropy: a threshold BLS signature produced every
 * three seconds by independent operators on separate continents.
 *
 * No single participant can predict or bias the output, which is a genuinely
 * different trust model from "one institution promises it is random".
 */
final class DrandSource implements EntropySource
{
    public function key(): string
    {
        return 'drand';
    }

    public function label(): string
    {
        return 'drand · League of Entropy';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Public;
    }

    public function origin(): string
    {
        return 'A threshold BLS signature jointly produced every three seconds by independent operators across several continents.';
    }

    public function refreshSeconds(): int
    {
        return 3;
    }

    public function collect(int $bytes): Material
    {
        $beacon = Http::timeout(1.5)
            ->get('https://api.drand.sh/public/latest')
            ->throw()
            ->json();

        $raw = @hex2bin($beacon['randomness'] ?? '');

        if ($raw === false || strlen($raw) < $bytes) {
            throw new \RuntimeException('drand returned an unusable beacon.');
        }

        $round = (int) $beacon['round'];

        return new Material(
            bytes: substr($raw, 0, $bytes),
            narrative: sprintf('drand round %s — no single operator could have predicted this value.', number_format($round)),
            proofUrl: "https://api.drand.sh/public/{$round}",
            reference: (string) $round,
            observedAt: new \DateTimeImmutable,
        );
    }
}
