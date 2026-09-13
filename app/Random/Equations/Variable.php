<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * A named unknown. Named Variable rather than Var because `var` is a reserved
 * word in PHP and cannot be a class name.
 */
final class Variable extends Node
{
    public function __construct(public readonly string $name) {}

    public function evaluate(array $bindings = []): float
    {
        // A missing binding is a bug in the caller, not a value: silently
        // defaulting to zero would make a guard that probes an expression report
        // "finite and well behaved" about a tree it never actually evaluated.
        if (! array_key_exists($this->name, $bindings)) {
            throw new \InvalidArgumentException("No value bound for [{$this->name}].");
        }

        return (float) $bindings[$this->name];
    }

    public function derive(string $var): Node
    {
        return new Num($this->name === $var ? 1.0 : 0.0);
    }

    public function toLatex(): string
    {
        // Greek names are written as symbols; single Latin letters are already
        // what LaTeX wants.
        return match ($this->name) {
            'theta' => '\theta',
            'alpha' => '\alpha',
            'beta' => '\beta',
            default => $this->name,
        };
    }

    public function toPlain(): string
    {
        return $this->name;
    }

    public function children(): array
    {
        return [];
    }

    public function withChildren(array $children): static
    {
        return $this;
    }

    public function precedence(): int
    {
        return self::PREC_ATOM;
    }

    protected function label(): string
    {
        return $this->name;
    }
}
