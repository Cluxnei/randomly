<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * A numeric leaf — an integer, a rational, or one of the two constants worth
 * naming.
 *
 * π and e carry a symbol alongside their value so they survive a round trip
 * through the printer: without it, `\pi` would come out the far side as
 * `3.141593`, and an expression sprinkled with truncated transcendentals reads
 * like a spreadsheet rather than like mathematics.
 */
final class Num extends Node
{
    public function __construct(
        public readonly float $value,
        private readonly ?string $latexSymbol = null,
        private readonly ?string $plainSymbol = null,
    ) {}

    public function isSymbolic(): bool
    {
        return $this->latexSymbol !== null;
    }

    public function isInteger(): bool
    {
        return ! $this->isSymbolic() && Fmt::isInteger($this->value);
    }

    public function toInt(): int
    {
        return (int) round($this->value);
    }

    public function evaluate(array $bindings = []): float
    {
        return $this->value;
    }

    public function derive(string $var): Node
    {
        return new self(0.0);
    }

    public function toLatex(): string
    {
        return $this->latexSymbol ?? Fmt::latex($this->value);
    }

    public function toPlain(): string
    {
        return $this->plainSymbol ?? Fmt::plain($this->value);
    }

    public function children(): array
    {
        return [];
    }

    public function withChildren(array $children): static
    {
        return $this;
    }

    /**
     * A negative number binds like a sum and a fraction binds like a quotient,
     * because that is how each of them has to be bracketed: `x^-3` and `2·1/2`
     * are both misread without the parentheses those precedences produce.
     */
    public function precedence(): int
    {
        if ($this->isSymbolic()) {
            return self::PREC_ATOM;
        }

        if ($this->value < 0) {
            return self::PREC_ADD;
        }

        return Fmt::isInteger($this->value) ? self::PREC_ATOM : self::PREC_MUL;
    }

    public function isNegative(): bool
    {
        return ! $this->isSymbolic() && $this->value < 0;
    }

    public function negate(): Node
    {
        return new self(-$this->value);
    }

    protected function label(): string
    {
        return $this->toPlain();
    }
}
