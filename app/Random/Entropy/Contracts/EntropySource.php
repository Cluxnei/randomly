<?php

declare(strict_types=1);

namespace App\Random\Entropy\Contracts;

use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;

interface EntropySource
{
    public function key(): string;

    public function label(): string;

    public function class(): EntropyClass;

    /** One sentence on the physical process behind this source. */
    public function origin(): string;

    /** How long collected material stays fresh, in seconds. */
    public function refreshSeconds(): int;

    /**
     * Collect at least $bytes of raw material.
     *
     * Free to throw: the pool catches, opens the circuit, and degrades to CSPRNG.
     */
    public function collect(int $bytes): Material;
}
