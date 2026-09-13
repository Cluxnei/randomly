<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Expr;
use App\Random\Equations\Matrix;
use App\Random\Equations\Node;
use App\Random\Equations\Problem;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Rng\Rng;

/**
 * Simultaneous equations that always have exactly one solution.
 *
 * Two things are decided before anything is written down: the solution vector,
 * and a coefficient matrix built by unimodular row operations so its determinant
 * is ±1. The right-hand side is then `A·x`, which makes the system consistent by
 * arithmetic rather than by luck.
 *
 * det = ±1 is not just non-zero for the sake of it. It means the inverse is
 * integral, so elimination by hand never produces a fraction on the way to an
 * answer that is a whole number — the failure that makes most randomly generated
 * systems miserable to solve even when they are solvable.
 */
final class SystemGenerator extends EquationsGenerator
{
    private const NAMES = ['x', 'y', 'z'];

    public function key(): string
    {
        return 'equations.system';
    }

    public function name(): string
    {
        return 'Systems';
    }

    public function tagline(): string
    {
        return '2×2 and 3×3, guaranteed to have one solution and to reach it without fractions.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('size', 'Size', ['2' => '2 × 2', '3' => '3 × 3'], default: '2')
            ->int('count', 'How many', default: 4, min: 1, max: 30)
            ->int('solution_range', 'Solutions within ±', default: 8, min: 2, max: 20);
    }

    public function problems(Rng $rng, Params $params): array
    {
        $n = max(2, min(3, (int) $params->string('size')));
        $range = $params->int('solution_range');
        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = $this->problem($rng, $n, $range);
        }

        return $problems;
    }

    private function problem(Rng $rng, int $n, int $range): Problem
    {
        $a = $this->coefficients($rng, $n);
        $solution = [];

        for ($i = 0; $i < $n; $i++) {
            $solution[] = $rng->intBetween(-$range, $range);
        }

        $rhs = Matrix::apply($a, $solution);
        $names = array_slice(self::NAMES, 0, $n);

        $plain = [];
        $latex = [];

        foreach ($a as $row => $coefficients) {
            $side = $this->row($coefficients, $names);
            $plain[] = $side->toPlain().' = '.$rhs[$row];
            $latex[] = $side->toLatex().' &= '.$rhs[$row];
        }

        $answer = implode(', ', array_map(
            fn (string $name, int $value): string => "{$name} = {$value}",
            $names,
            $solution,
        ));

        return new Problem(
            prompt: implode(' ;  ', $plain),
            // `cases` stacks the equations and aligns them on the equals sign,
            // which is the only way a 3×3 system is readable at a glance.
            promptLatex: '\begin{cases}'.implode(' \\\\ ', $latex).'\end{cases}',
            answer: $answer,
            answerLatex: $answer,
            extra: ['determinant' => Matrix::determinant($a)],
            check: ['matrix' => $a, 'rhs' => $rhs, 'solution' => $solution, 'names' => $names],
        );
    }

    /**
     * A unimodular matrix with no one-term rows.
     *
     * Row operations leave some rows of the identity untouched, and a system
     * containing `y = 0` has given away a third of itself before the student
     * starts. Correct, solvable, and a waste of the question — so a few are
     * drawn and the first properly mixed one wins.
     *
     * @return list<list<int>>
     */
    private function coefficients(Rng $rng, int $n): array
    {
        $fallback = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $a = Matrix::unimodular($rng, $n);
            $fallback ??= $a;

            $mixed = array_reduce(
                $a,
                fn (bool $carry, array $row): bool => $carry && count(array_filter($row)) >= 2,
                true,
            );

            if ($mixed) {
                return $a;
            }
        }

        return $fallback;
    }

    /** @param list<int> $coefficients @param list<string> $names */
    private function row(array $coefficients, array $names): Node
    {
        $terms = [];

        foreach ($coefficients as $index => $coefficient) {
            if ($coefficient === 0) {
                continue;
            }

            $terms[] = Expr::scale($coefficient, Expr::v($names[$index]));
        }

        // A unimodular row can come out all zeros only if the matrix is
        // singular, which it is not — but an equation reading `0 = 0` would be
        // such a catastrophic thing to print that it is worth one guard.
        return $terms === [] ? Expr::n(0) : Expr::add(...$terms);
    }
}
