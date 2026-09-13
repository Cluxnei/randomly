<?php

declare(strict_types=1);

namespace App\Random\Equations;

use App\Random\Rng\Rng;

/**
 * Forward generation: a probabilistic context-free grammar over the AST.
 *
 * ```
 * E → E + E | E − E | E × E | E ÷ E | E^k | f(E) | leaf
 * f → sin | cos | tan | ln | exp | √ | |·|
 * ```
 *
 * Two things make this work rather than explode.
 *
 * The first is that the probability of stopping rises with depth,
 * `P(leaf | d) = 1 − (1 − p₀)^(d+1)`, so the expected node count is finite and
 * the depth control behaves the way a reader expects it to: nudging it up makes
 * expressions a bit bigger, not exponentially bigger.
 *
 * The second is that the domain guards run *at build time*. The tempting
 * alternative — grow whatever, evaluate, throw it away if it came out NaN — is
 * wrong twice over: it burns an unbounded amount of the seed stream, and it
 * biases the grammar towards whichever shapes happen to survive, which is not
 * the distribution anybody asked for. Here a division builds a denominator it
 * has checked is non-zero, a logarithm is handed an argument that cannot be
 * non-positive, and only overflow — which no local rule can predict — falls back
 * on regrowing a subtree.
 *
 * Forward generation is for looking at. The answers it produces are ugly by
 * nature, which is why every generator whose problems are meant to be *solved*
 * is built backwards from its answer instead.
 */
final class Grammar
{
    /**
     * Where the guards look.
     *
     * A handful of fixed points rather than random ones, and irrational-looking
     * ones on purpose: probing at 1 and 2 would miss a pole at 1.5, and probing
     * at points drawn from the Rng would make the guard's verdict depend on the
     * stream position, so the same expression would pass in one place and fail
     * in another.
     */
    private const PROBES = [-2.7, -1.3, 0.4, 1.6, 3.2];

    /** Past this the expression has stopped being about anything. */
    private const MAX_MAGNITUDE = 1e12;

    /** A denominator this close to zero is a denominator of zero. */
    private const ZERO_EPSILON = 1e-6;

    /** How near tan may come to its asymptote before the argument is rejected. */
    private const POLE_EPSILON = 0.15;

    /**
     * Attempts before a guard gives up and takes its documented fallback.
     *
     * Bounded because an unbounded loop against a deterministic stream is not a
     * retry, it is a hang: the same bytes produce the same subtree forever if
     * the stream position never advances past the failure.
     */
    private const ATTEMPTS = 4;

    /** @param list<string> $functions @param list<string> $variables */
    public function __construct(
        private readonly Rng $rng,
        private readonly int $maxDepth = 3,
        private readonly array $functions = [],
        private readonly array $variables = ['x'],
        private readonly bool $constants = false,
        private readonly float $leafProbability = 0.25,
    ) {}

    /**
     * A whole expression, not just a node.
     *
     * grow() is honest about the grammar — at depth 0 it terminates a quarter of
     * the time — but "3" is not an expression tree and nor is "x", and a
     * generator whose job is to show you a grown expression cannot hand back a
     * leaf. So the root is required to branch, and to actually use a variable if
     * variables were asked for, before it counts as grown.
     */
    public function growRoot(): Node
    {
        if ($this->maxDepth < 1) {
            return $this->leaf();
        }

        $best = null;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $node = $this->production(0);

            if (! $this->isWellBehaved($node)) {
                continue;
            }

            $best ??= $node;

            // Judged after simplification as well as before: `x/x` is three
            // nodes and mentions a variable, and it is still the number 1.
            $reduced = $node->simplify();
            $minimum = max(3, min(5, $this->maxDepth + 1));

            if ($node->nodeCount() >= $minimum
                && ($this->variables === [] || ($reduced->variables() !== [] && $reduced->nodeCount() >= 3))) {
                return $node;
            }
        }

        return $best ?? $this->leaf();
    }

    public function grow(int $depth = 0): Node
    {
        if ($depth >= $this->maxDepth || $this->rng->bool($this->terminalProbability($depth))) {
            return $this->leaf();
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $node = $this->production($depth);

            if ($this->isWellBehaved($node)) {
                return $node;
            }
        }

        // Overflow is the only failure a local guard cannot foresee — nothing
        // about `a^b` says whether b's subtree will evaluate to 40 — so it is
        // also the only one that falls all the way back to a leaf.
        return $this->leaf();
    }

    /** P(leaf | d) = 1 − (1 − p₀)^(d+1). */
    public function terminalProbability(int $depth): float
    {
        return 1.0 - (1.0 - $this->leafProbability) ** ($depth + 1);
    }

    private function production(int $depth): Node
    {
        $choices = ['+', '-', '*', '/', '^'];
        $weights = [3, 3, 3, 2, 1.5];

        if ($this->functions !== []) {
            $choices[] = 'f';
            $weights[] = 3;
        }

        return match ($this->rng->weighted($choices, $weights)) {
            '+' => new Binary('+', $this->grow($depth + 1), $this->grow($depth + 1)),
            '-' => new Binary('-', $this->grow($depth + 1), $this->grow($depth + 1)),
            '*' => new Binary('*', $this->grow($depth + 1), $this->grow($depth + 1)),
            '/' => $this->quotient($depth),
            '^' => $this->power($depth),
            default => $this->call($depth),
        };
    }

    /**
     * `a ÷ b`, with b grown until it is provably non-zero at every probe.
     *
     * The fallback when it will not cooperate is multiplication rather than
     * another attempt, because a denominator that keeps coming out zero is
     * usually a subtree shape that always will — and `a × b` is a real
     * expression, not a degraded one.
     */
    private function quotient(int $depth): Node
    {
        $numerator = $this->grow($depth + 1);
        $denominator = $this->nonZero($depth + 1);

        return new Binary($denominator === null ? '*' : '/', $numerator, $denominator ?? $this->grow($depth + 1));
    }

    private function nonZero(int $depth): ?Node
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $node = $this->grow($depth);

            foreach ($this->probes() as $bindings) {
                $value = $node->evaluate($bindings);

                if (! is_finite($value) || abs($value) < self::ZERO_EPSILON) {
                    continue 2;
                }
            }

            return $node;
        }

        return null;
    }

    /**
     * `a^k` with k an integer in [−3, 4] and never 0 or 1.
     *
     * The clamp is what keeps the value finite; excluding 0 and 1 is what keeps
     * the expression worth printing, since `u^1` and `u^0` are a whole subtree
     * spent saying nothing.
     */
    private function power(int $depth): Node
    {
        $exponent = $this->rng->pick([-3, -2, -1, 2, 3, 4]);

        // A negative exponent is a division in disguise and needs the same
        // guard the division does.
        $base = $exponent < 0 ? $this->nonZero($depth + 1) : $this->grow($depth + 1);

        if ($base === null) {
            return new Binary('^', $this->grow($depth + 1), Expr::n($this->rng->pick([2, 3])));
        }

        return new Binary('^', $base, Expr::n($exponent));
    }

    private function call(int $depth): Node
    {
        $name = $this->rng->pick($this->functions);
        $argument = $this->grow($depth + 1);

        return match ($name) {
            // ln(|u| + 1) is defined everywhere and is never zero at the bottom
            // of a fraction, which the plain |u| form would be whenever u is.
            'ln' => Expr::ln(Expr::add($this->magnitude($argument), Expr::n(1))),
            'sqrt' => Expr::sqrt($this->magnitude($argument)),
            'tan' => $this->tangent($depth, $argument),
            'abs' => $this->magnitude($argument),
            default => new Unary($name, $argument),
        };
    }

    /**
     * |u|, except that a number already knows its own sign.
     *
     * `ln(|5| + 1)` is the guard leaving its fingerprints on the output: correct,
     * and it reads as a bug rather than as an expression.
     */
    private function magnitude(Node $node): Node
    {
        return $node instanceof Num && ! $node->isSymbolic()
            ? Expr::n(abs($node->value))
            : Expr::abs($node);
    }

    /**
     * tan is the one function whose domain hole is interior rather than at an
     * edge, so it cannot be wrapped into safety the way ln and √ can — the
     * argument has to be rejected instead.
     */
    private function tangent(int $depth, Node $argument): Node
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            if ($this->avoidsPole($argument)) {
                return Expr::tan($argument);
            }

            $argument = $this->grow($depth + 1);
        }

        return Expr::sin($argument);
    }

    private function avoidsPole(Node $argument): bool
    {
        foreach ($this->probes() as $bindings) {
            $value = $argument->evaluate($bindings);

            if (! is_finite($value)) {
                return false;
            }

            // Distance from the nearest odd multiple of π/2.
            $offset = fmod(abs($value) - M_PI_2, M_PI);

            if (min($offset, M_PI - $offset) < self::POLE_EPSILON) {
                return false;
            }
        }

        return true;
    }

    private function leaf(): Node
    {
        $choices = ['int', 'rational'];
        $weights = [55, 8];

        if ($this->variables !== []) {
            $choices[] = 'var';
            $weights[] = 90;
        }

        if ($this->constants) {
            $choices[] = 'constant';
            $weights[] = 10;
        }

        return match ($this->rng->weighted($choices, $weights)) {
            'var' => Expr::v($this->rng->pick($this->variables)),
            'constant' => $this->rng->bool() ? Expr::pi() : Expr::euler(),
            'rational' => $this->rational(),
            // 0 and 1 are excluded deliberately: every `x + 0` and `x · 1` that
            // reaches a reader is the grammar spending a node to say nothing,
            // and that is exactly what makes generated maths look generated.
            default => Expr::n($this->rng->intBetween(2, 12) * ($this->rng->bool(0.25) ? -1 : 1)),
        };
    }

    private function rational(): Node
    {
        $denominator = $this->rng->pick([2, 3, 4]);
        $numerator = $this->rng->intBetween(1, 9);

        // A numerator divisible by the denominator is just an integer wearing a
        // fraction's clothes.
        if ($numerator % $denominator === 0) {
            $numerator++;
        }

        return Expr::n($numerator / $denominator);
    }

    /** @return list<array<string, float>> */
    public function probes(): array
    {
        if ($this->variables === []) {
            return [[]];
        }

        $sets = [];

        foreach (self::PROBES as $index => $probe) {
            $bindings = [];

            // Rotated rather than shared, so a guard cannot be fooled by an
            // expression that only misbehaves when x and y differ.
            foreach (array_values($this->variables) as $position => $name) {
                $bindings[$name] = self::PROBES[($index + $position) % count(self::PROBES)];
            }

            $sets[] = $bindings;
        }

        return $sets;
    }

    public function isWellBehaved(Node $node): bool
    {
        foreach ($this->probes() as $bindings) {
            $value = $node->evaluate($bindings);

            if (! is_finite($value) || abs($value) > self::MAX_MAGNITUDE) {
                return false;
            }
        }

        return true;
    }
}
