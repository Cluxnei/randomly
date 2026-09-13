<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * A single-argument node: negation, or one of the functions the module's
 * grammar knows how to differentiate.
 */
final class Unary extends Node
{
    public const FUNCTIONS = ['sin', 'cos', 'tan', 'ln', 'exp', 'sqrt', 'abs'];

    public function __construct(
        public readonly string $op,
        public readonly Node $operand,
    ) {
        if ($op !== 'neg' && ! in_array($op, self::FUNCTIONS, true)) {
            throw new \InvalidArgumentException("Unknown unary operator [{$op}].");
        }
    }

    public function evaluate(array $bindings = []): float
    {
        $x = $this->operand->evaluate($bindings);

        // Out-of-domain returns NAN rather than throwing. The grammar's guards
        // are what keep a generated expression inside its domain; this path is
        // the probe those guards run, so it has to be able to answer "that is
        // not a number" without unwinding the build.
        return match ($this->op) {
            'neg' => -$x,
            'sin' => sin($x),
            'cos' => cos($x),
            'tan' => cos($x) === 0.0 ? NAN : tan($x),
            'ln' => $x > 0 ? log($x) : NAN,
            'exp' => $x > 709 ? INF : exp($x),
            'sqrt' => $x >= 0 ? sqrt($x) : NAN,
            'abs' => abs($x),
        };
    }

    public function derive(string $var): Node
    {
        $u = $this->operand;
        $du = $u->derive($var);

        return match ($this->op) {
            'neg' => Expr::neg($du),
            'sin' => Expr::mul(Expr::cos($u), $du),
            'cos' => Expr::neg(Expr::mul(Expr::sin($u), $du)),
            // sec² is not in the function set, so the quotient form is what the
            // rest of the module can actually evaluate and print.
            'tan' => Expr::div($du, Expr::pow(Expr::cos($u), Expr::n(2))),
            'ln' => Expr::div($du, $u),
            'exp' => Expr::mul(Expr::exp($u), $du),
            'sqrt' => Expr::div($du, Expr::mul(Expr::n(2), Expr::sqrt($u))),
            // Correct everywhere |u| is differentiable, which is everywhere but
            // u = 0. The calculus generator never selects abs for that reason;
            // the rule is here so the expression grammar stays differentiable.
            'abs' => Expr::mul($du, Expr::div($u, Expr::abs($u))),
        };
    }

    public function toLatex(): string
    {
        $inner = $this->operand->toLatex();

        return match ($this->op) {
            'neg' => '-'.$this->bracket($this->operand, self::PREC_MUL, true),
            'sqrt' => '\sqrt{'.$inner.'}',
            'abs' => '\left|'.$inner.'\right|',
            'exp' => 'e^{'.$inner.'}',
            'ln' => '\ln\left('.$inner.'\right)',
            default => '\\'.$this->op.'\left('.$inner.'\right)',
        };
    }

    public function toPlain(): string
    {
        $inner = $this->operand->toPlain();

        return match ($this->op) {
            'neg' => '-'.$this->bracket($this->operand, self::PREC_MUL, false),
            'abs' => '|'.$inner.'|',
            default => $this->op.'('.$inner.')',
        };
    }

    public function children(): array
    {
        return [$this->operand];
    }

    public function withChildren(array $children): static
    {
        return new self($this->op, $children[0]);
    }

    public function precedence(): int
    {
        return $this->op === 'neg' ? self::PREC_ADD : self::PREC_ATOM;
    }

    public function isNegative(): bool
    {
        return $this->op === 'neg';
    }

    public function negate(): Node
    {
        return $this->op === 'neg' ? $this->operand : $this;
    }

    protected function simplifyOnce(): Node
    {
        $operand = $this->operand->simplifyOnce();

        // Double negation, a negated literal folded into its own sign, and the
        // same for anything else already carrying a minus: the chain rule
        // through cos produces `-(-15sin 3x)` constantly, and nobody writes that.
        if ($this->op === 'neg' && $operand->isNegative()) {
            return $operand->negate();
        }

        // Zero is the case the rule above cannot see, because zero is not
        // negative — and `-0` is the single most obviously machine-generated
        // thing this module could print.
        if ($this->op === 'neg' && $operand instanceof Num && ! $operand->isSymbolic()) {
            return new Num(-$operand->value);
        }

        if ($operand instanceof Num && ! $operand->isSymbolic()) {
            // Only the exact folds. sin(2) collapsing to 0.909297 would make the
            // output strictly worse to read, and readability is the only thing
            // simplify() exists for.
            if ($this->op === 'abs') {
                return new Num(abs($operand->value));
            }

            if ($this->op === 'sqrt' && Fmt::isInteger($operand->value) && Fmt::isPerfectSquare($operand->toInt())) {
                return new Num(sqrt($operand->value));
            }
        }

        return new self($this->op, $operand);
    }

    protected function label(): string
    {
        return $this->op;
    }
}
