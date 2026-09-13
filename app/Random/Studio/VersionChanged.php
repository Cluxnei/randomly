<?php

declare(strict_types=1);

namespace App\Random\Studio;

/**
 * A permalink made against an older version of a generator.
 *
 * Failing loudly is the whole point: reproducibility that quietly stops
 * reproducing is worse than no reproducibility at all.
 */
final class VersionChanged extends \RuntimeException
{
    public function __construct(
        public readonly string $generatorKey,
        public readonly int $generatedWith,
        public readonly int $current,
    ) {
        parent::__construct(sprintf(
            '%s is now v%d. This link was made with v%d and can no longer be reproduced exactly.',
            $generatorKey,
            $current,
            $generatedWith,
        ));
    }
}
