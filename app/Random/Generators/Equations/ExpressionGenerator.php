<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Fmt;
use App\Random\Equations\Grammar;
use App\Random\Equations\Node;
use App\Random\Equations\Problem;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Rng\Rng;

/**
 * The forward half of the module: a grammar grows an expression, and then it is
 * evaluated.
 *
 * This is the generator where the answers are allowed to be ugly, because the
 * expression is the output rather than a question about it. Everything else in
 * the module runs the other way round.
 */
final class ExpressionGenerator extends EquationsGenerator
{
    /** Where a value is reported from. Integers, and never zero — f(0) is rarely the interesting one. */
    private const SAMPLE_POINTS = [-5, -4, -3, -2, -1, 1, 2, 3, 4, 5];

    public function key(): string
    {
        return 'equations.expression';
    }

    public function name(): string
    {
        return 'Expression Tree';
    }

    public function tagline(): string
    {
        return 'A probabilistic grammar grows the expression; you get the tree it grew from too.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('depth', 'Maximum depth', default: 3, min: 1, max: 6, help: 'A hard cut. Expressions usually stop short of it.')
            ->enum('functions', 'Function set', [
                'none' => 'Arithmetic only',
                'roots' => 'Roots and absolute value',
                'trig' => 'Trigonometry',
                'full' => 'Everything',
            ], default: 'trig')
            ->int('variables', 'Variables', default: 1, min: 0, max: 3, help: 'Zero makes every expression a closed number.')
            ->bool('constants', 'Allow π and e')
            ->bool('show_value', 'Evaluate it', default: true)
            ->int('count', 'How many', default: 3, min: 1, max: 40);
    }

    public function problems(Rng $rng, Params $params): array
    {
        $variables = array_slice(['x', 'y', 'z'], 0, $params->int('variables'));

        $grammar = new Grammar(
            rng: $rng,
            maxDepth: $params->int('depth'),
            functions: $this->functions($params->string('functions')),
            variables: $variables,
            constants: $params->bool('constants'),
        );

        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = $this->problem($rng, $grammar, $params->bool('show_value'), $variables);
        }

        return $problems;
    }

    /** @return list<string> */
    private function functions(string $set): array
    {
        return match ($set) {
            'none' => [],
            'roots' => ['sqrt', 'abs'],
            'trig' => ['sin', 'cos', 'tan'],
            default => ['sin', 'cos', 'tan', 'ln', 'exp', 'sqrt', 'abs'],
        };
    }

    private function problem(Rng $rng, Grammar $grammar, bool $showValue, array $variables): Problem
    {
        $tree = $grammar->growRoot();

        if (! $showValue) {
            // Without a value the interesting fact about an expression is its
            // shape, so that is what the answer slot carries rather than a blank.
            return Problem::of($tree, sprintf('depth %d, %d nodes', $tree->depth(), $tree->nodeCount()), [
                'tree' => $tree->toTree(),
            ], ['expression' => $tree]);
        }

        $bindings = $this->samplePoint($rng, $grammar, $tree, $variables);
        $value = $tree->evaluate($bindings);

        return Problem::of($tree, $this->answer($bindings, $value), [
            'tree' => $tree->toTree(),
            'bindings' => $bindings,
            'value' => is_finite($value) ? $value : null,
        ], ['expression' => $tree, 'bindings' => $bindings]);
    }

    /**
     * A point to report the value at, chosen to be one the expression survives.
     *
     * The grammar's guards prove the tree is well behaved at its own probe
     * points, which are not integers — and an expression that is finite at 1.6
     * can still have a pole at 2. So integer candidates are tried until one
     * works, and the grammar's own probe is the backstop, because that one is
     * already known good.
     */
    private function samplePoint(Rng $rng, Grammar $grammar, Node $tree, array $variables): array
    {
        if ($variables === []) {
            return [];
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $bindings = [];

            foreach ($variables as $name) {
                $bindings[$name] = (float) $rng->pick(self::SAMPLE_POINTS);
            }

            $value = $tree->evaluate($bindings);

            if (is_finite($value) && abs($value) < 1e12) {
                return $bindings;
            }
        }

        return $grammar->probes()[0];
    }

    private function answer(array $bindings, float $value): string
    {
        $at = implode(', ', array_map(
            fn (string $name, float $point): string => $name.' = '.Fmt::plain($point),
            array_keys($bindings),
            $bindings,
        ));

        if (! is_finite($value)) {
            return $at === '' ? 'undefined' : "undefined at {$at}";
        }

        $printed = Fmt::isInteger($value) ? Fmt::plain($value) : Fmt::decimal($value);

        return $at === '' ? "= {$printed}" : "{$at}  →  {$printed}";
    }

    protected function meta(Params $params, array $problems): array
    {
        $nodes = array_map(fn (Problem $p): int => $p->check['expression']->nodeCount(), $problems);
        $depths = array_map(fn (Problem $p): int => $p->check['expression']->depth(), $problems);

        return [
            // What the depth control actually bought. The grammar's terminal
            // probability rises with depth, so raising the cap makes trees
            // somewhat bigger rather than exponentially bigger — and these two
            // numbers are where that claim can be checked rather than believed.
            'mean_nodes' => round(array_sum($nodes) / max(1, count($nodes)), 1),
            'deepest' => $depths === [] ? 0 : max($depths),
        ];
    }
}
