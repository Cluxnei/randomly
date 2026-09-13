<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Expr;
use App\Random\Equations\Fmt;
use App\Random\Equations\Node;
use App\Random\Equations\Problem;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Rng\Rng;

/**
 * Linear equations, built from the answer outwards.
 *
 * Pick the solution `r` first; then any `a` and `b` you like give a correct
 * equation, because the right-hand side is computed as `a·r + b` rather than
 * drawn. There is nothing to check afterwards and nothing to reject, and the
 * answer is a whole number because it was chosen to be one.
 *
 * The same trick carries the harder tiers. `a(x + b) = c·x + d` solves to
 * `(d − ab)/(a − c)`, which is a fraction for almost every `d` — so `d` is not
 * drawn either. It is computed from the `r` we already decided on, and the
 * division that would have been inexact never happens.
 *
 * Rational solutions work the same way one level up: pick `r = p/q`, then draw
 * every coefficient as a multiple of `q`, and the equation stays in whole
 * numbers while its answer is a proper fraction.
 */
final class LinearGenerator extends EquationsGenerator
{
    public function key(): string
    {
        return 'equations.linear';
    }

    public function name(): string
    {
        return 'Linear Equations';
    }

    public function tagline(): string
    {
        return 'Built root-first, so the answer is always clean and never needs checking.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('tier', 'Difficulty', [
                'one_step' => 'One step — x + b = c',
                'two_step' => 'Two steps — ax + b = c',
                'two_sided' => 'Both sides — ax + b = cx + d',
                'brackets' => 'Brackets — a(x + b) = cx + d',
                'mixed' => 'Mixed',
            ], default: 'two_step')
            ->int('count', 'How many', default: 8, min: 1, max: 50)
            ->bool('integer_solutions', 'Whole-number answers', default: true);
    }

    public function problems(Rng $rng, Params $params): array
    {
        $tier = $params->string('tier');
        $integers = $params->bool('integer_solutions');
        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = $this->problem(
                $rng,
                $tier === 'mixed' ? $rng->pick(['one_step', 'two_step', 'two_sided', 'brackets']) : $tier,
                $integers,
            );
        }

        return $problems;
    }

    private function problem(Rng $rng, string $tier, bool $integers): Problem
    {
        [$p, $q] = $this->solution($rng, $integers);

        [$lhs, $rhs] = match ($tier) {
            'one_step' => $this->oneStep($rng, $p, $q),
            'two_sided' => $this->twoSided($rng, $p, $q),
            'brackets' => $this->brackets($rng, $p, $q),
            default => $this->twoStep($rng, $p, $q),
        };

        return Problem::equation($lhs, $rhs, 'x = '.Fmt::plain($p / $q), check: ['solution' => $p / $q]);
    }

    /**
     * The solution as an exact fraction p/q, never as a float.
     *
     * Every constant in the finished equation is then computed in whole numbers
     * — a coefficient of `q·m` against a root of `p/q` gives `m·p` — so the
     * claim that these problems are exact by construction survives contact with
     * binary floating point. Computing `6 · (7/3)` as doubles gives
     * 14.000000000000002, which prints as 14 and is not 14.
     *
     * @return array{int, int}
     */
    private function solution(Rng $rng, bool $integers): array
    {
        if ($integers) {
            return [$this->nonZero($rng, -12, 12), 1];
        }

        $q = $rng->pick([2, 3, 4]);
        $p = $this->nonZero($rng, -14, 14);

        // A numerator that divides out leaves a whole number, which is a fine
        // answer but not the one this switch was turned on to get.
        return [$p % $q === 0 ? $p + 1 : $p, $q];
    }

    /** @return array{Node, Node} */
    private function oneStep(Rng $rng, int $p, int $q): array
    {
        $x = Expr::v('x');

        // `x + b = c` cannot have a fractional answer without a fractional
        // constant on the right, which is a worse problem than it is a harder
        // one — so a fractional root takes the `ax = c` form instead.
        if ($q > 1 || $rng->bool()) {
            $m = $this->coefficient($rng, 2, max(3, intdiv(9, $q)));

            return [Expr::scale($m * $q, $x), Expr::n($m * $p)];
        }

        $b = $this->nonZero($rng, -20, 20);

        return [Expr::add($x, Expr::n($b)), Expr::n($p + $b)];
    }

    /** @return array{Node, Node} */
    private function twoStep(Rng $rng, int $p, int $q): array
    {
        $m = $this->coefficient($rng, 2, max(3, intdiv(12, $q)));
        $b = $this->nonZero($rng, -20, 20);

        return [
            Expr::add(Expr::scale($m * $q, Expr::v('x')), Expr::n($b)),
            Expr::n($m * $p + $b),
        ];
    }

    /** @return array{Node, Node} */
    private function twoSided(Rng $rng, int $p, int $q): array
    {
        [$m, $k] = $this->distinctCoefficients($rng, $q);
        $b = $this->nonZero($rng, -20, 20);

        return [
            Expr::add(Expr::scale($m * $q, Expr::v('x')), Expr::n($b)),
            Expr::add(Expr::scale($k * $q, Expr::v('x')), Expr::n(($m - $k) * $p + $b)),
        ];
    }

    /** @return array{Node, Node} */
    private function brackets(Rng $rng, int $p, int $q): array
    {
        [$m, $k] = $this->distinctCoefficients($rng, $q);

        // A leading 1 would print the brackets as decoration rather than as work.
        $a = (abs($m) < 2 ? 2 : $m) * $q;
        $m = intdiv($a, $q);
        $b = $this->nonZero($rng, -12, 12);

        return [
            Expr::mul(Expr::n($a), Expr::add(Expr::v('x'), Expr::n($b))),
            Expr::add(Expr::scale($k * $q, Expr::v('x')), Expr::n(($m - $k) * $p + $a * $b)),
        ];
    }

    /**
     * Two multipliers that differ.
     *
     * Equal ones would cancel the unknown off both sides entirely and leave
     * either a contradiction or an identity — never the single solution the
     * answer claims.
     *
     * @return array{int, int}
     */
    private function distinctCoefficients(Rng $rng, int $q): array
    {
        // Bounded by the denominator, because every coefficient is multiplied by
        // it: without this a quarter-valued root produces `32(x + 8) = …`, which
        // is not a harder problem, only a wider one.
        $m = $this->coefficient($rng, 2, max(3, intdiv(11, $q)));
        $k = $this->coefficient($rng, -max(3, intdiv(8, $q)), max(3, intdiv(8, $q)));

        return [$m, $k === $m ? $k - 1 : $k];
    }

    private function coefficient(Rng $rng, int $lo, int $hi): int
    {
        return $this->nonZero($rng, $lo, $hi);
    }
}
