<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * One node of an expression tree.
 *
 * Four concrete types — Num, Variable, Unary, Binary — and no evaluator that
 * touches eval(). A generated problem is data the whole way through: it can be
 * evaluated at a point, differentiated, tidied up and printed twice (LaTeX for
 * the page, plain for the clipboard) without ever becoming a string that has to
 * be parsed back.
 *
 * `Var` would have been the name the docs use, but `var` is a reserved word in
 * PHP and cannot be a class name, so the type is Variable.
 *
 * Nodes are immutable. Every transformation returns a new tree, which is what
 * makes it safe to hand the same subtree to two parents — the identity
 * corruptions in particular rebuild one node deep inside a shared structure.
 */
abstract class Node
{
    /** Loosest binding first: additive < multiplicative < power < atom. */
    public const PREC_ADD = 1;

    public const PREC_MUL = 2;

    public const PREC_POW = 3;

    public const PREC_ATOM = 4;

    /**
     * @param  array<string, float>  $bindings
     */
    abstract public function evaluate(array $bindings = []): float;

    abstract public function derive(string $var): Node;

    abstract public function toLatex(): string;

    abstract public function toPlain(): string;

    /** @return list<Node> */
    abstract public function children(): array;

    /** @param list<Node> $children */
    abstract public function withChildren(array $children): static;

    abstract public function precedence(): int;

    /**
     * Does this tree carry an explicit minus sign out front?
     *
     * Asked by the printer so that a negative term folds into the operator
     * beside it: `3x^2 + (-5)x` is written `3x^2 - 5x`, and `(-2)e^{-2x}` is
     * written `-2e^{-2x}`. Without this every expanded polynomial and every
     * derivative would announce itself as machine output on the first term.
     */
    public function isNegative(): bool
    {
        return false;
    }

    /** The positive twin of a tree that isNegative(). Undefined for anything else. */
    public function negate(): Node
    {
        return $this;
    }

    /**
     * The cheap rewrite rules only: identities, constant folding, double
     * negation, and pulling numeric factors to the front of a product.
     *
     * Deliberately not a CAS. Its whole job is keeping a symbolically-derived
     * answer readable — turning `3·x^2·1 + 0` into `3x^2` — and every rule beyond
     * that is a rabbit hole with no bottom. Run to a fixed point because one
     * rewrite exposes the next: folding `2-1` to `1` is what lets `x^1` collapse.
     */
    final public function simplify(): Node
    {
        $node = $this;

        // Eight passes is far more than any tree this module builds needs; the
        // cap exists so a rule pair that rewrites in a circle cannot hang a
        // request rather than because anything is expected to use it.
        for ($i = 0; $i < 8; $i++) {
            $next = $node->simplifyOnce();

            if ($next->toPlain() === $node->toPlain()) {
                return $next;
            }

            $node = $next;
        }

        return $node;
    }

    protected function simplifyOnce(): Node
    {
        return $this;
    }

    /** @return list<string> the variable names this tree reads, in first-seen order */
    final public function variables(): array
    {
        $names = [];

        foreach ($this->children() as $child) {
            foreach ($child->variables() as $name) {
                $names[$name] = true;
            }
        }

        if ($this instanceof Variable) {
            $names[$this->name] = true;
        }

        return array_keys($names);
    }

    final public function nodeCount(): int
    {
        return array_reduce(
            $this->children(),
            fn (int $carry, Node $child): int => $carry + $child->nodeCount(),
            1,
        );
    }

    final public function depth(): int
    {
        $deepest = 0;

        foreach ($this->children() as $child) {
            $deepest = max($deepest, $child->depth());
        }

        return $deepest + ($this->children() === [] ? 0 : 1);
    }

    /** True when the tree evaluates to something finite at every probe point. */
    final public function isFiniteAt(array $bindings): bool
    {
        return is_finite($this->evaluate($bindings));
    }

    /**
     * An indented rendering of the tree itself.
     *
     * The Expression Tree generator promises you the tree and not just its
     * output; without this it would be the one generator whose interesting half
     * is invisible.
     */
    final public function toTree(string $indent = ''): string
    {
        $lines = [$indent.$this->label()];

        foreach ($this->children() as $child) {
            $lines[] = $child->toTree($indent.'  ');
        }

        return implode("\n", $lines);
    }

    abstract protected function label(): string;

    /**
     * Print a child, parenthesised only when the grammar actually requires it.
     *
     * Over-parenthesising is the failure mode this module cares most about:
     * `((3+0)·1)/((2)^1)` is technically a valid expression and reads as noise.
     * A child needs brackets when it binds more loosely than its parent, or when
     * it sits on the right of an operator that is not associative there — `a-(b-c)`
     * and `a/(b·c)` both change meaning if the brackets go.
     */
    final protected function bracket(Node $child, int $minimum, bool $latex): string
    {
        $text = $latex ? $child->toLatex() : $child->toPlain();

        return $child->precedence() < $minimum
            ? ($latex ? '\left('.$text.'\right)' : '('.$text.')')
            : $text;
    }
}
