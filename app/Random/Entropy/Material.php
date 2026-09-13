<?php

declare(strict_types=1);

namespace App\Random\Entropy;

/**
 * Raw entropy as collected from one source, plus the story it came with.
 */
final readonly class Material
{
    public function __construct(
        public string $bytes,
        public string $narrative,
        public ?string $proofUrl = null,
        public ?string $reference = null,
        public ?\DateTimeImmutable $observedAt = null,
    ) {}
}
