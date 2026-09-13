<?php

declare(strict_types=1);

namespace App\Random\Generators\Contracts;

use App\Random\Generators\Renderer;

abstract class BaseGenerator implements Generator
{
    public function version(): int
    {
        return 1;
    }

    public function renderer(): Renderer
    {
        return Renderer::Text;
    }

    public function isSensitive(): bool
    {
        return false;
    }

    public function usesEntropy(): bool
    {
        return true;
    }

    public function isReproducible(): bool
    {
        return ! $this instanceof NeedsData;
    }
}
