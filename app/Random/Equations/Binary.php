<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * A two-argument node: the four operations plus exponentiation.
 */
final class Binary extends Node
{
    public const OPERATORS = ['+', '-', '*', '/', '^'];

    public function __construct(
        public readonly string $op,
        public readonly Node $left,
        public readonly Node $right,
    ) {
        if (! in_array($op, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Unknown binary operator [{$op}].");
        }
    }

    public function evaluate(array $bindings = []): float
    {
        $a = $this->left->evaluate($bindings);
        $b = $this->right->evaluate($bindings);

        return match ($this->op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            // Division by zero is NAN rather than a PHP DivisionByZeroError: this
            // is the probe the grammar's zero-divisor guard runs, and it has to
            // be able to report the problem instead of aborting the build.
            '/' => $b == 0.0 ? NAN : $a / $b,
            '^' => $this->power($a, $b),
        };
    }

    private function power(float $base, float $exponent): float
    {
        // A negative base to a fractional power is not real, and PHP's pow()
        // answers NAN for it anyway — said out loud here so the guard reads.
        if ($base < 0 && ! Fmt::isInteger($exponent)) {
            return NAN;
        }

        if ($base == 0.0 && $exponent < 0) {
            return NAN;
        }

        return $base ** $exponent;
    }

    public function derive(string $var): Node
    {
        $u = $this->left;
        $v = $this->right;
        $du = $u->derive($var);
        $dv = $v->derive($var);

        return match ($this->op) {
            '+' => Expr::add($du, $dv),
            '-' => Expr::sub($du, $dv),
            '*' => Expr::add(Expr::mul($du, $v), Expr::mul($u, $dv)),
            '/' => Expr::div(
                Expr::sub(Expr::mul($du, $v), Expr::mul($u, $dv)),
                Expr::pow($v, Expr::n(2)),
            ),
            '^' => $this->derivePower($var, $u, $v, $du, $dv),
        };
    }

    /**
     * Power rule when the exponent is constant, logarithmic differentiation when
     * it is not.
     *
     * Splitting the two is not an optimisation. `x^x` genuinely needs
     * `u^v·(v'·ln u + v·u'/u)`, but applying that general form to `x^3` would put
     * an `ln x` in the answer to a question about a cubic — correct, unusable.
     */
    private function derivePower(string $var, Node $u, Node $v, Node $du, Node $dv): Node
    {
        if (! in_array($var, $v->variables(), true)) {
            return Expr::mul($v, Expr::pow($u, Expr::sub($v, Expr::n(1))), $du);
        }

        return Expr::mul(
            Expr::pow($u, $v),
            Expr::add(Expr::mul($dv, Expr::ln($u)), Expr::div(Expr::mul($v, $du), $u)),
        );
    }

    public function toLatex(): string
    {
        return $this->render(true);
    }

    public function toPlain(): string
    {
        return $this->render(false);
    }

    private function render(bool $latex): string
    {
        return match ($this->op) {
            '+' => $this->renderSum($latex),
            '-' => $this->renderDifference($latex),
            '*' => $this->renderProduct($latex),
            '/' => $this->renderQuotient($latex),
            '^' => $this->renderPower($latex),
        };
    }

    /**
     * `a + (-b)` is written `a - b`.
     *
     * Not cosmetic: a derivative or an expanded quadratic produces negative terms
     * constantly, and `3x^2 + -5x + -2` is the single fastest way to make
     * generated output look generated.
     */
    private function renderSum(bool $latex): string
    {
        $left = $this->bracket($this->left, self::PREC_ADD, $latex);

        if ($this->right->isNegative()) {
            return $left.' - '.$this->bracket($this->right->negate(), self::PREC_MUL, $latex);
        }

        return $left.' + '.$this->bracket($this->right, self::PREC_ADD, $latex);
    }

    private function renderDifference(bool $latex): string
    {
        $left = $this->bracket($this->left, self::PREC_ADD, $latex);

        if ($this->right->isNegative()) {
            return $left.' + '.$this->bracket($this->right->negate(), self::PREC_ADD, $latex);
        }

        // The right operand of a subtraction binds tighter than a sum does:
        // dropping the brackets from `a - (b - c)` changes the answer.
        return $left.' - '.$this->bracket($this->right, self::PREC_MUL, $latex);
    }

    private function renderProduct(bool $latex): string
    {
        // A coefficient of ±1 is never written out. simplify() removes these, but
        // the expression grammar deliberately does not simplify, so the printer
        // has to hold the line too — `1 · x` reaching a worksheet is unforgivable
        // in a way that a slightly deep tree is not.
        if ($this->isLiteral($this->left, 1.0)) {
            return $this->bracket($this->right, self::PREC_MUL, $latex);
        }

        if ($this->isNegative()) {
            return '-'.$this->negate()->{$latex ? 'toLatex' : 'toPlain'}();
        }

        $left = $this->bracket($this->left, self::PREC_MUL, $latex);
        $right = $this->bracket($this->right, self::PREC_MUL, $latex);

        return $left.($this->isImplicitProduct() ? '' : ($latex ? ' \cdot ' : '*')).$right;
    }

    /**
     * When the dot can be dropped: `3x`, `2x^2`, `2(x - 3)`, `(x + 1)(x - 2)`.
     *
     * The rule is that juxtaposition has to stay unambiguous in *plain* text as
     * well as in LaTeX, which is what rules out `xy` and `3 2`: a factor may be
     * silent only when it is a numeral, or when it is bracketed anyway, or when
     * it is a named function that carries its own parentheses.
     */
    private function isImplicitProduct(): bool
    {
        $left = $this->left;
        $leftOk = ($left instanceof Num && ! $left->isSymbolic() && $left->isInteger())
            || $left->precedence() < self::PREC_MUL
            // A named call carries its own parentheses, so `sin(a)cos(b)` is as
            // unambiguous in plain text as it is in LaTeX. `xy` is not, which is
            // why a bare variable on the left does not qualify.
            || ($left instanceof Unary && $left->op !== 'neg')
            // `2x(x + 6)`: a product that already prints without a dot can carry
            // another silent factor without becoming ambiguous.
            || ($left instanceof self && $left->op === '*' && $left->isImplicitProduct());

        if (! $leftOk) {
            return false;
        }

        $right = $this->right;

        // A power is judged by its base: `8(2x + 1)^3` is as unambiguous as
        // `8x^3`, and both are how the answer would be written by hand.
        if ($right instanceof self && $right->op === '^') {
            $right = $right->left;
        }

        // `cos(x)x` is legal and reads backwards — a coefficient belongs in front
        // of its function, not behind it. simplify() moves it; the printer, which
        // the expression grammar never simplifies, keeps the dot instead.
        if ($left instanceof Unary && $this->isBareVariable($right)) {
            return false;
        }

        // A chain of factors is judged by the one that comes next, so
        // `12·(x·e^{x²})` prints `12x·e^{x²}` rather than `12*x*e^{x²}`.
        if ($right instanceof self && $right->op === '*') {
            $right = $right->left;
        }

        return $right->precedence() < self::PREC_MUL
            || $right instanceof Variable
            || ($right instanceof Num && $right->isSymbolic())
            || ($right instanceof Unary && $right->op !== 'neg');
    }

    private function renderQuotient(bool $latex): string
    {
        if ($this->isNegative()) {
            return '-'.$this->negate()->{$latex ? 'toLatex' : 'toPlain'}();
        }

        if ($latex) {
            // \frac groups both halves on its own, so brackets here would only
            // ever be noise.
            return '\frac{'.$this->left->toLatex().'}{'.$this->right->toLatex().'}';
        }

        return $this->bracket($this->left, self::PREC_MUL, false)
            .'/'
            .$this->bracket($this->right, self::PREC_POW, false);
    }

    private function renderPower(bool $latex): string
    {
        $base = $this->left;

        // `sin(x)^2` is written `sin²x` by every textbook in existence, and the
        // superscript-after-the-name form is what a reader expects to see.
        if ($latex && $base instanceof Unary && $base->op !== 'neg' && $base->op !== 'abs'
            && $this->right instanceof Num && $this->right->isInteger() && $this->right->value > 0) {
            $name = $base->op === 'ln' ? '\ln' : '\\'.$base->op;

            if ($base->op !== 'sqrt' && $base->op !== 'exp') {
                return $name.'^{'.$this->right->toLatex().'}\left('.$base->operand->toLatex().'\right)';
            }
        }

        if ($latex) {
            // `exp` prints as `e^{u}`, so squaring it without brackets produces
            // `e^{a}^{2}` — a double superscript, which is a TeX syntax error
            // rather than an ugly rendering. Nothing about the node's precedence
            // can see that; only its printed form can.
            $grouped = $base instanceof Unary && $base->op === 'exp'
                ? '\left('.$base->toLatex().'\right)'
                : $this->bracket($base, self::PREC_ATOM, true);

            // The exponent is inside braces, so it never needs brackets of its own.
            return $grouped.'^{'.$this->right->toLatex().'}';
        }

        return $this->bracket($base, self::PREC_ATOM, false).'^'.$this->bracket($this->right, self::PREC_ATOM, false);
    }

    public function children(): array
    {
        return [$this->left, $this->right];
    }

    public function withChildren(array $children): static
    {
        return new self($this->op, $children[0], $children[1]);
    }

    public function precedence(): int
    {
        // A product printed with a leading minus — `-2x` — has to bracket like a
        // sum does, or `y · -2x` comes out without the parentheses it needs.
        if ($this->isNegative()) {
            return self::PREC_ADD;
        }

        return match ($this->op) {
            '+', '-' => self::PREC_ADD,
            '*', '/' => self::PREC_MUL,
            '^' => self::PREC_POW,
        };
    }

    /**
     * A product leads with a minus when its coefficient does; a sum leads with
     * one when its first term does. `x + (-6 - 3)` has to be recognised as
     * subtracting something, or it prints as `x + -6 - 3`.
     */
    public function isNegative(): bool
    {
        // Never a power. `(-10)^2` is a hundred, so pulling the minus out front
        // would turn it into minus a hundred — the printer would then show
        // `-10^2` for a positive number. Sums and products distribute over a
        // sign; exponentiation does not.
        return $this->op !== '^' && $this->left->isNegative();
    }

    /**
     * Negating a sum flips the operator as well as the first term: `−(a − b)` is
     * `−a + b`, and the tempting `(−a) − b` is a different number. Nothing has
     * ever been harder to spot in generated output than a sign that is wrong
     * only sometimes.
     */
    public function negate(): Node
    {
        if (! $this->isNegative()) {
            return $this;
        }

        return match ($this->op) {
            '+' => new self('-', $this->left->negate(), $this->right),
            '-' => new self('+', $this->left->negate(), $this->right),
            default => new self($this->op, $this->left->negate(), $this->right),
        };
    }

    protected function simplifyOnce(): Node
    {
        $l = $this->left->simplifyOnce();
        $r = $this->right->simplifyOnce();

        $folded = $this->fold($l, $r);

        if ($folded !== null) {
            return $folded;
        }

        return $this->rewrite($l, $r) ?? new self($this->op, $l, $r);
    }

    /**
     * Constant folding, but only where the answer stays writable.
     *
     * `7/2` folds, because Fmt prints it as a half. `1/3` folds for the same
     * reason. `2^0.5` does not, because the honest answer is 1.4142136 and a
     * worksheet with that in it has lost an exact value it was holding.
     */
    private function fold(Node $l, Node $r): ?Node
    {
        if (! $l instanceof Num || ! $r instanceof Num || $l->isSymbolic() || $r->isSymbolic()) {
            return null;
        }

        $value = match ($this->op) {
            '+' => $l->value + $r->value,
            '-' => $l->value - $r->value,
            '*' => $l->value * $r->value,
            '/' => $r->value == 0.0 ? null : $l->value / $r->value,
            // A fractional exponent would leave the rational world behind.
            '^' => Fmt::isInteger($r->value) && abs($r->value) <= 8 ? $this->power($l->value, $r->value) : null,
        };

        if ($value === null || ! is_finite($value)) {
            return null;
        }

        return Fmt::isInteger($value) || Fmt::asFraction($value) !== null ? new Num($value) : null;
    }

    private function rewrite(Node $l, Node $r): ?Node
    {
        $lZero = $this->isLiteral($l, 0.0);
        $rZero = $this->isLiteral($r, 0.0);

        return match ($this->op) {
            '+' => match (true) {
                $lZero => $r,
                $rZero => $l,
                default => null,
            },
            '-' => match (true) {
                $rZero => $l,
                $lZero => Expr::neg($r),
                // u − u is zero for every u, with no domain caveat at all — the
                // one cancellation in here that is unconditionally true.
                $l->toPlain() === $r->toPlain() => new Num(0.0),
                // `a - (-b)` is `a + b`. The printer would have written the same
                // thing, but collapsing it here lets later passes see a sum.
                $r instanceof Unary && $r->op === 'neg' => Expr::add($l, $r->operand),
                default => null,
            },
            '*' => $this->rewriteProduct($l, $r, $lZero, $rZero),
            '/' => $this->rewriteQuotient($l, $r, $lZero),
            '^' => match (true) {
                $this->isLiteral($r, 1.0) => $l,
                $this->isLiteral($r, 0.0) => new Num(1.0),
                $this->isLiteral($l, 1.0) => new Num(1.0),
                // (u^a)^b is u^(ab). The quotient rule reaches `(x^3)^2` on its
                // own, and nobody writes a squared cube.
                $l instanceof self && $l->op === '^' && $l->right instanceof Num && $r instanceof Num
                    && ! $l->right->isSymbolic() && ! $r->isSymbolic() => Expr::pow($l->left, new Num($l->right->value * $r->value)),
                default => null,
            },
        };
    }

    /**
     * The one cancellation worth doing: a numeric factor shared by both halves.
     *
     * The chain rule through a square root leaves `2/(2√(2x+3))`, and pulling the
     * coefficients out front lets them fold against each other and vanish.
     * Anything more than this — cancelling a common `x`, expanding a bracket —
     * is a computer algebra system, and this is not one.
     */
    private function rewriteQuotient(Node $l, Node $r, bool $lZero): ?Node
    {
        if ($this->isLiteral($r, 1.0)) {
            return $l;
        }

        if ($lZero) {
            return new Num(0.0);
        }

        if ($l instanceof Unary && $l->op === 'neg') {
            return Expr::neg(Expr::div($l->operand, $r));
        }

        if ($l->toPlain() === $r->toPlain()) {
            return new Num(1.0);
        }

        [$topCoefficient, $top] = $this->splitCoefficient($l);
        [$bottomCoefficient, $bottom] = $this->splitCoefficient($r);

        if ($topCoefficient !== null && $bottomCoefficient !== null
            && $bottomCoefficient->value != 0.0
            && ($top !== null || $bottom !== null)) {
            $ratio = Expr::div($topCoefficient, $bottomCoefficient);

            return match (true) {
                $top === null => Expr::div($ratio, $bottom),
                $bottom === null => Expr::mul($ratio, $top),
                default => Expr::mul($ratio, Expr::div($top, $bottom)),
            };
        }

        return null;
    }

    /**
     * Products get the most attention because the derivative rules produce the
     * most rubbish there: a power rule leaves `3·x^2·1`, a chain rule leaves
     * `4·(2x+1)^3·2`, and both want their numbers gathered at the front.
     */
    private function rewriteProduct(Node $l, Node $r, bool $lZero, bool $rZero): ?Node
    {
        return match (true) {
            $lZero, $rZero => new Num(0.0),
            $this->isLiteral($l, 1.0) => $r,
            $this->isLiteral($r, 1.0) => $l,
            $this->isLiteral($l, -1.0) => Expr::neg($r),
            $this->isLiteral($r, -1.0) => Expr::neg($l),
            default => $this->gather($l, $r)
                // Negation climbs out of the product: `2·(-x)` reads as `-2x`
                // once, and as a puzzle every other time.
                ?? match (true) {
                    $l instanceof Unary && $l->op === 'neg' => Expr::neg(Expr::mul($l->operand, $r)),
                    $r instanceof Unary && $r->op === 'neg' => Expr::neg(Expr::mul($l, $r->operand)),
                    // u·(v/u) → v, and its mirror. Strictly this holds only away
                    // from u = 0, and a real CAS would say so. It is here because
                    // the product rule on `x·ln x` ends at `ln x + x·(1/x)`, and
                    // an answer key printing that instead of `ln x + 1` has
                    // stopped being an answer key.
                    $r instanceof self && $r->op === '/' && $r->right->toPlain() === $l->toPlain() => $r->left,
                    $l instanceof self && $l->op === '/' && $l->right->toPlain() === $r->toPlain() => $l->left,
                    $l instanceof Num && ! $l->isSymbolic() && $r instanceof self && $r->op === '/' => Expr::div(Expr::mul($l, $r->left), $r->right),
                    // The variable goes in front of the function: the quotient
                    // rule on `sin x / x` lands on `cos(x)·x`, and everyone
                    // writes that `x cos x`.
                    $l instanceof Unary && $l->op !== 'neg' && $this->isBareVariable($r) => Expr::mul($r, $l),
                    default => null,
                },
        };
    }

    /**
     * Every number in a product chain, multiplied together and moved to the front.
     *
     * This is the rule that earns its keep. The chain rule through `−5e^{x²}`
     * leaves `(−5)·(e^{x²}·(2·x))` — three factors, two of them numbers, sitting
     * on opposite sides of a function call — and pairwise rewriting can never
     * bring them together because they are never each other's sibling. Flattening
     * the whole chain can, and once the coefficients meet they fold.
     *
     * Returns null when the chain is already canonical, which is what stops the
     * fixed-point loop going round for ever.
     */
    private function gather(Node $l, Node $r): ?Node
    {
        $factors = [...$this->factors($l), ...$this->factors($r)];
        $coefficient = 1.0;
        $rest = [];
        $numbers = 0;

        foreach ($factors as $factor) {
            if ($factor instanceof Num && ! $factor->isSymbolic()) {
                $coefficient *= $factor->value;
                $numbers++;

                continue;
            }

            $rest[] = $factor;
        }

        $canonical = $numbers === 0
            || ($numbers === 1 && $factors[0] instanceof Num && ! $factors[0]->isSymbolic());

        if ($canonical) {
            return null;
        }

        if ($rest === []) {
            return new Num($coefficient);
        }

        return Expr::scale($coefficient, Expr::mul(...$rest));
    }

    /** @return list<Node> the product chain under this node, flattened */
    private function factors(Node $node): array
    {
        return $node instanceof self && $node->op === '*'
            ? [...$this->factors($node->left), ...$this->factors($node->right)]
            : [$node];
    }

    /**
     * A node's leading numeric factor and whatever is left of it.
     *
     * A bare number is all coefficient and no remainder; `2√u` splits into 2 and
     * `√u`; anything else has no coefficient to speak of. Written this way so
     * the cancellation above sees `2` and `2√u` as the same kind of thing, which
     * pattern matching on `a·b / c·d` never could — and that pair is exactly
     * what a square root's derivative produces.
     *
     * @return array{?Num, ?Node}
     */
    private function splitCoefficient(Node $node): array
    {
        if ($node instanceof Num && ! $node->isSymbolic()) {
            return [$node, null];
        }

        if ($node instanceof self && $node->op === '*' && $node->left instanceof Num && ! $node->left->isSymbolic()) {
            return [$node->left, $node->right];
        }

        return [null, $node];
    }

    /** A variable, or a variable raised to a power — the things a coefficient attaches to. */
    private function isBareVariable(Node $node): bool
    {
        if ($node instanceof self && $node->op === '^') {
            $node = $node->left;
        }

        return $node instanceof Variable;
    }

    private function isLiteral(Node $node, float $value): bool
    {
        return $node instanceof Num && ! $node->isSymbolic() && $node->value === $value;
    }

    protected function label(): string
    {
        return $this->op;
    }
}
