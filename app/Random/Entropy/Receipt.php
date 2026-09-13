<?php

declare(strict_types=1);

namespace App\Random\Entropy;

use DateTimeImmutable;

/**
 * The provenance of a single generated result — the most shareable object here.
 */
final readonly class Receipt
{
    public function __construct(
        public string $sourceKey,
        public string $sourceLabel,
        public EntropyClass $class,
        public string $narrative,
        public ?string $proofUrl,
        public ?string $reference,
        public DateTimeImmutable $observedAt,
        public bool $degraded,
        public bool $mixedWithCsprng,
        public int $latencyMs,
        public bool $cached = false,
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->sourceKey,
            'source_label' => $this->sourceLabel,
            'class' => $this->class->value,
            'class_label' => $this->class->label(),
            'caveat' => $this->class->caveat(),
            'narrative' => $this->narrative,
            'proof_url' => $this->proofUrl,
            'reference' => $this->reference,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'degraded' => $this->degraded,
            'mixed_with_csprng' => $this->mixedWithCsprng,
            'latency_ms' => $this->latencyMs,
            'cached' => $this->cached,
        ];
    }
}
