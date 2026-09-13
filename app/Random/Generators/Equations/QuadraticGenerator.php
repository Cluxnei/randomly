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
 * Quadratics, from the roots down.
 *
 * `a(x − r₁)(x − r₂)` expands to `a·x² − a(r₁+r₂)·x + a·r₁·r₂`: integer
 * coefficients, integer roots, guaranteed factorable, and not one discriminant
 * computed along the way. Drawing `a, b, c` and hoping instead gives a
 * factorable quadratic about a tenth of the time and an answer full of surds the
 * rest.
 *
 * Rational roots are the same construction one level out — `a(q₁x − p₁)(q₂x − p₂)`
 * keeps every coefficient whole while the roots are fractions.
 *
 * The surd tier is the exception that has to run forwards, because it is
 * *defined* by a property of the coefficients rather than of the roots. Even
 * there nothing is rejected blindly: `c` is walked downwards, which moves
 * `Δ = b² − 4ac` up by `4a` each step, and the walk stops at the first Δ that is
 * positive and not a perfect square. Non-squares are dense, so it stops almost
 * immediately.
 */
final class QuadraticGenerator extends EquationsGenerator
{
    public function key(): string
    {
        return 'equations.quadratic';
    }

    public function name(): string
    {
        return 'Quadratics';
    }

    public function tagline(): string
    {
        return 'Integer, rational or surd roots — built from the roots, so they factor.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('roots', 'Root type', [
                'integer' => 'Integers',
                'rational' => 'Fractions',
                'surd' => 'Surds (irrational)',
                'mixed' => 'Mixed',
            ], default: 'integer')
            ->bool('monic', 'Leading coefficient 1', default: false, help: 'Off lets the x² term carry a coefficient.')
            ->int('count', 'How many', default: 6, min: 1, max: 50);
    }

    public function problems(Rng $rng, Params $params): array
    {
        $kind = $params->string('roots');
        $monic = $params->bool('monic');
        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = match ($kind === 'mixed' ? $rng->pick(['integer', 'rational', 'surd']) : $kind) {
                'rational' => $this->rationalRoots($rng, $monic),
                'surd' => $this->surdRoots($rng, $monic),
                default => $this->integerRoots($rng, $monic),
            };
        }

        return $problems;
    }

    private function integerRoots(Rng $rng, bool $monic): Problem
    {
        $a = $monic ? 1 : $this->nonZero($rng, -4, 4);
        $r1 = $rng->intBetween(-9, 9);
        $r2 = $rng->intBetween(-9, 9);

        $coefficients = [$a, -$a * ($r1 + $r2), $a * $r1 * $r2];

        return $this->problem(
            $coefficients,
            $r1 === $r2
                ? 'x = '.$r1.' (a double root)'
                : 'x = '.min($r1, $r2).'  or  x = '.max($r1, $r2),
            ['factored' => $this->factored($a, [1, -$r1], [1, -$r2])],
            [(float) $r1, (float) $r2],
        );
    }

    /** Roots p₁/q₁ and p₂/q₂, from `a(q₁x − p₁)(q₂x − p₂)`. */
    private function rationalRoots(Rng $rng, bool $monic): Problem
    {
        $a = $monic ? 1 : $rng->pick([1, 1, -1]);
        [$p1, $q1] = $this->fraction($rng);
        [$p2, $q2] = $this->fraction($rng);

        $coefficients = [
            $a * $q1 * $q2,
            -$a * ($q1 * $p2 + $q2 * $p1),
            $a * $p1 * $p2,
        ];

        $roots = [$p1 / $q1, $p2 / $q2];
        sort($roots);

        return $this->problem(
            $coefficients,
            'x = '.Fmt::plain($roots[0]).'  or  x = '.Fmt::plain($roots[1]),
            ['factored' => $this->factored($a, [$q1, -$p1], [$q2, -$p2])],
            $roots,
        );
    }

    /**
     * @return array{int, int} numerator and denominator, already in lowest terms
     */
    private function fraction(Rng $rng): array
    {
        $q = $rng->pick([2, 2, 3, 3, 4, 5]);
        $p = $this->nonZero($rng, -11, 11);

        // Sharing a factor with the denominator would quietly reduce to a
        // simpler fraction, or to a whole number, and the tier would stop doing
        // what it says.
        while (Fmt::gcd($p, $q) !== 1) {
            $p++;
        }

        return [$p, $q];
    }

    private function surdRoots(Rng $rng, bool $monic): Problem
    {
        $a = $monic ? 1 : $rng->intBetween(1, 3);
        $b = $this->nonZero($rng, -11, 11);
        $c = $rng->intBetween(-6, 6);

        // Δ climbs by 4a with every step down, so this terminates in a couple of
        // iterations and needs no escape hatch beyond the range of c itself.
        while (true) {
            $discriminant = $b * $b - 4 * $a * $c;

            if ($discriminant > 0 && ! Fmt::isPerfectSquare($discriminant)) {
                break;
            }

            $c--;
        }

        [$outside, $inside] = Fmt::simplifySurd($discriminant);
        $divisor = Fmt::gcd(Fmt::gcd(abs($b), $outside), 2 * $a);

        $numerator = intdiv(-$b, $divisor);
        $surd = intdiv($outside, $divisor);
        $denominator = intdiv(2 * $a, $divisor);

        $roots = [
            (-$b - $outside * sqrt($inside)) / (2 * $a),
            (-$b + $outside * sqrt($inside)) / (2 * $a),
        ];

        return $this->problem(
            [$a, $b, $c],
            $this->surdAnswer($numerator, $surd, $inside, $denominator, false),
            [
                'answer_latex_override' => $this->surdAnswer($numerator, $surd, $inside, $denominator, true),
                'discriminant' => $discriminant,
            ],
            $roots,
        );
    }

    /** `x = (3 ± 2√5)/4`, reduced, with the trivial denominators left off. */
    private function surdAnswer(int $numerator, int $surd, int $inside, int $denominator, bool $latex): string
    {
        $root = $latex ? '\sqrt{'.$inside.'}' : 'sqrt('.$inside.')';
        $term = ($surd === 1 ? '' : $surd).$root;
        $plusMinus = $latex ? '\pm' : '±';

        $top = $numerator === 0
            ? "{$plusMinus}{$term}"
            : "{$numerator} {$plusMinus} {$term}";

        if ($denominator === 1) {
            return 'x = '.$top;
        }

        return $latex
            ? 'x = \frac{'.$top.'}{'.$denominator.'}'
            : "x = ({$top})/{$denominator}";
    }

    /** @param list<int|float> $coefficients @param list<float> $roots */
    private function problem(array $coefficients, string $answer, array $extra, array $roots): Problem
    {
        $polynomial = Expr::polynomial($coefficients);
        $answerLatex = $extra['answer_latex_override'] ?? $answer;
        unset($extra['answer_latex_override']);

        return new Problem(
            prompt: $polynomial->toPlain().' = 0',
            promptLatex: $polynomial->toLatex().' = 0',
            answer: $answer,
            answerLatex: $answerLatex,
            extra: $extra,
            check: ['polynomial' => $polynomial, 'roots' => $roots],
        );
    }

    /** `2(x - 3)(x + 1)`, for the answer key to show its working. */
    private function factored(int $a, array $first, array $second): string
    {
        $bracket = fn (array $pair): Node => Expr::add(
            Expr::scale($pair[0], Expr::v('x')),
            Expr::n($pair[1]),
        );

        return Expr::mul(Expr::n($a), $bracket($first), $bracket($second))->toPlain();
    }
}
