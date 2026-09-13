<?php

declare(strict_types=1);

namespace App\Random\Equations;

use App\Random\Rng\Rng;

/**
 * Break exactly one thing.
 *
 * The whole appeal of the True-or-False generator is that the broken half is
 * hard to spot, which rules out the obvious implementation — regenerate a random
 * right-hand side — because a wrong answer that looks nothing like the right one
 * is not a question, it is a formality. So a corruption changes a single sign, a
 * single coefficient or a single function name, deep in a statement that is
 * otherwise correct, and the reader has to actually know the identity.
 *
 * Nothing here decides whether the result is false. That is the verifier's job:
 * some corruptions land on another true identity, and only evaluating the thing
 * can tell.
 */
final class Corruption
{
    /**
     * @return array{Node, string}|null the corrupted tree and a description, or
     *                                  null when nothing in the tree can be bent
     */
    public static function apply(Rng $rng, Node $root): ?array
    {
        $candidates = [];

        self::collect($root, [], $candidates, null);

        if ($candidates === []) {
            return null;
        }

        $chosen = $rng->pick($candidates);

        return [
            self::replaceAt($root, $chosen['path'], $chosen['node']),
            $chosen['description'],
        ];
    }

    /**
     * Every single-token edit available in the tree, each as a path plus the
     * node to put there.
     *
     * Collected exhaustively and then picked from uniformly, rather than walking
     * the tree making a coin flip at each node: uniform-over-sites means a big
     * statement is corrupted as evenly as a small one, where the walk would
     * almost always strike near the root.
     *
     * @param  list<int>  $path
     * @param  list<array{path: list<int>, node: Node, description: string}>  $out
     */
    private static function collect(Node $node, array $path, array &$out, ?Node $parent, int $index = 0): void
    {
        foreach (self::mutations($node, $parent, $index) as [$replacement, $description]) {
            $out[] = ['path' => $path, 'node' => $replacement, 'description' => $description];
        }

        foreach ($node->children() as $child => $subtree) {
            self::collect($subtree, [...$path, $child], $out, $node, $child);
        }
    }

    /** @return list<array{Node, string}> */
    private static function mutations(Node $node, ?Node $parent, int $index): array
    {
        if ($node instanceof Num && ! $node->isSymbolic() && Fmt::isInteger($node->value)) {
            $value = $node->toInt();

            // A corruption has to stay invisible until the reader does the
            // maths. Zeroing a coefficient deletes a whole term, and dropping an
            // exponent to 1 leaves `sin(x)^1` sitting on the page — both give
            // themselves away as machine damage rather than as a mistake
            // somebody could plausibly make.
            $exponent = $parent instanceof Binary && $parent->op === '^' && $index === 1;
            $allowed = fn (int $candidate): bool => $candidate !== 0 && ! ($exponent && abs($candidate) === 1);

            return array_values(array_filter([
                $allowed($value + 1) ? [new Num((float) ($value + 1)), "the coefficient {$value} became ".($value + 1)] : null,
                $allowed($value - 1) ? [new Num((float) ($value - 1)), "the coefficient {$value} became ".($value - 1)] : null,
                $allowed(-$value) ? [new Num((float) -$value), "the sign of {$value} flipped"] : null,
            ]));
        }

        if ($node instanceof Binary && ($node->op === '+' || $node->op === '-')) {
            $flipped = $node->op === '+' ? '-' : '+';

            return [[new Binary($flipped, $node->left, $node->right), "a {$node->op} became a {$flipped}"]];
        }

        if ($node instanceof Unary) {
            // sin for cos is the classic slip, and the one a reader has to know
            // the identity to catch — the shape of the statement is unchanged.
            $swap = match ($node->op) {
                'sin' => 'cos',
                'cos' => 'sin',
                'tan' => 'sin',
                'ln' => 'exp',
                'exp' => 'ln',
                default => null,
            };

            return $swap === null ? [] : [[new Unary($swap, $node->operand), "a {$node->op} became a {$swap}"]];
        }

        return [];
    }

    /** @param list<int> $path */
    public static function replaceAt(Node $root, array $path, Node $replacement): Node
    {
        if ($path === []) {
            return $replacement;
        }

        $index = array_shift($path);
        $children = $root->children();
        $children[$index] = self::replaceAt($children[$index], $path, $replacement);

        return $root->withChildren($children);
    }
}
