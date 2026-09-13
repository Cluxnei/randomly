<?php

declare(strict_types=1);

namespace App\Random;

use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Module;
use Illuminate\Support\Collection;

/**
 * The catalogue.
 *
 * Populated from an explicit list in config/randomly.php rather than by scanning
 * the filesystem: one line per generator is greppable, controls display order,
 * and makes it obvious what ships.
 */
final class GeneratorRegistry
{
    /** @var Collection<string, Generator> */
    private Collection $generators;

    /** @param iterable<Generator> $generators */
    public function __construct(iterable $generators)
    {
        $this->generators = collect($generators)->keyBy(fn (Generator $g) => $g->key());
    }

    /** @return Collection<string, Generator> */
    public function all(): Collection
    {
        return $this->generators;
    }

    /** @return Collection<string, Generator> */
    public function module(Module $module): Collection
    {
        return $this->generators->filter(fn (Generator $g) => $g->module() === $module);
    }

    /** @return Collection<string, Collection<string, Generator>> */
    public function byModule(): Collection
    {
        return $this->generators->groupBy(fn (Generator $g) => $g->module()->value);
    }

    public function find(string $key): ?Generator
    {
        return $this->generators->get($key);
    }

    public function findOrFail(string $key): Generator
    {
        return $this->find($key) ?? throw new \RuntimeException("No generator registered as [{$key}].");
    }

    public function has(string $key): bool
    {
        return $this->generators->has($key);
    }

    /** Catalogue payload for /api/v1/generators and the library page. */
    public function toArray(): array
    {
        return $this->generators->map(fn (Generator $g) => [
            'key' => $g->key(),
            'name' => $g->name(),
            'tagline' => $g->tagline(),
            'module' => $g->module()->value,
            'renderer' => $g->renderer()->value,
            'version' => $g->version(),
            'sensitive' => $g->isSensitive(),
            'params' => $g->schema()->toArray(),
        ])->values()->all();
    }
}
