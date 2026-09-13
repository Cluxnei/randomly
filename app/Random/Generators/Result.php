<?php

declare(strict_types=1);

namespace App\Random\Generators;

final readonly class Result
{
    public function __construct(
        public mixed $value,
        public string $display,
        public array $meta = [],
    ) {}

    public function withMeta(array $extra): self
    {
        return new self($this->value, $this->display, [...$this->meta, ...$extra]);
    }
}
