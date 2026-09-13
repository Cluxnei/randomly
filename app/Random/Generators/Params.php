<?php

declare(strict_types=1);

namespace App\Random\Generators;

final readonly class Params
{
    public function __construct(
        private array $values,
        private array $data = [],
    ) {}

    /**
     * Attach externally fetched material.
     *
     * Deliberately separate from the parameter values, because parameters feed the
     * seed fingerprint and fetched data must not. A Wikipedia article that changes
     * between two requests would otherwise derive a different stream and break
     * replay for reasons the user could never see.
     */
    public function withData(array $data): self
    {
        return new self($this->values, $data);
    }

    public function data(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        return $this->data[$key] ?? $default;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->values[$name] ?? $default;
    }

    public function int(string $name, int $default = 0): int
    {
        return (int) ($this->values[$name] ?? $default);
    }

    public function float(string $name, float $default = 0.0): float
    {
        return (float) ($this->values[$name] ?? $default);
    }

    public function bool(string $name, bool $default = false): bool
    {
        return (bool) ($this->values[$name] ?? $default);
    }

    public function string(string $name, string $default = ''): string
    {
        return (string) ($this->values[$name] ?? $default);
    }

    public function all(): array
    {
        return $this->values;
    }

    /**
     * Parameters take part in seed derivation, so the hash must be stable across
     * requests and machines — hence sorted keys and canonical JSON.
     */
    public function fingerprint(): array
    {
        $values = $this->values;
        ksort($values);

        return $values;
    }
}
