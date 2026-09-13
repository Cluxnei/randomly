<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;

/**
 * The floor under everything else. Never fails, never blocks, always available.
 */
final class CsprngSource implements EntropySource
{
    public function key(): string
    {
        return 'csprng';
    }

    public function label(): string
    {
        return 'Operating System CSPRNG';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Cryptographic;
    }

    public function origin(): string
    {
        return 'The kernel entropy pool — interrupt timings, device jitter and, on this machine, the CPU hardware RNG.';
    }

    public function refreshSeconds(): int
    {
        return 0;
    }

    public function collect(int $bytes): Material
    {
        return new Material(
            bytes: random_bytes($bytes),
            narrative: 'Drawn from the kernel entropy pool on the server that rendered this page.',
            observedAt: new \DateTimeImmutable,
        );
    }
}
