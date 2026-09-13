<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Problem;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Rng\Rng;

/**
 * Find the pattern.
 *
 * The one design decision worth naming is how many terms to show. A "what comes
 * next" question with three terms is not a puzzle, it is an inkblot: 2, 4, 8
 * continues 16 as a geometric sequence and 14 as a quadratic one, and both
 * answers are right. Five terms pins every family here to a single continuation,
 * so the stated answer is the answer rather than one of several.
 */
final class SequenceGenerator extends EquationsGenerator
{
    public function key(): string
    {
        return 'equations.sequence';
    }

    public function name(): string
    {
        return 'Find the Pattern';
    }

    public function tagline(): string
    {
        return 'Arithmetic, geometric, quadratic or Fibonacci-like — with enough terms to be unambiguous.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('kind', 'Pattern', [
                'arithmetic' => 'Arithmetic — constant difference',
                'geometric' => 'Geometric — constant ratio',
                'quadratic' => 'Quadratic — constant second difference',
                'fibonacci' => 'Fibonacci-like — sum of the previous two',
                'mixed' => 'Mixed',
            ], default: 'mixed')
            ->int('terms', 'Terms shown', default: 5, min: 4, max: 9)
            ->int('count', 'How many', default: 5, min: 1, max: 40);
    }

    public function problems(Rng $rng, Params $params): array
    {
        $kind = $params->string('kind');
        $terms = $params->int('terms');
        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = $this->problem(
                $rng,
                $kind === 'mixed' ? $rng->pick(['arithmetic', 'geometric', 'quadratic', 'fibonacci']) : $kind,
                $terms,
            );
        }

        return $problems;
    }

    private function problem(Rng $rng, string $kind, int $terms): Problem
    {
        [$sequence, $rule] = match ($kind) {
            'geometric' => $this->geometric($rng, $terms),
            'quadratic' => $this->quadratic($rng, $terms),
            'fibonacci' => $this->fibonacci($rng, $terms),
            default => $this->arithmetic($rng, $terms),
        };

        // One term more than is shown: the last one is the answer.
        $next = array_pop($sequence);
        $shown = implode(', ', $sequence);

        return new Problem(
            prompt: $shown.', …',
            promptLatex: implode(',\; ', $sequence).',\; \ldots',
            answer: $next.'   ('.$rule.')',
            answerLatex: (string) $next,
            extra: ['kind' => $kind, 'rule' => $rule, 'terms' => $sequence, 'next' => $next],
            check: ['kind' => $kind, 'terms' => $sequence, 'next' => $next],
        );
    }

    /** @return array{list<int>, string} */
    private function arithmetic(Rng $rng, int $terms): array
    {
        $first = $rng->intBetween(-12, 20);
        $difference = $this->nonZero($rng, -9, 9);

        return [
            array_map(fn (int $n): int => $first + $n * $difference, range(0, $terms)),
            'arithmetic, difference '.$difference,
        ];
    }

    /** @return array{list<int>, string} */
    private function geometric(Rng $rng, int $terms): array
    {
        $first = $this->nonZero($rng, -6, 6);
        // Ratio ±1 is not a geometric progression anybody wants to be shown, and
        // beyond 4 the last term of a nine-term run runs to six figures.
        $ratio = $rng->pick([2, 2, 3, 3, -2, 4]);

        $sequence = [];
        $value = $first;

        for ($n = 0; $n <= $terms; $n++) {
            $sequence[] = $value;
            $value *= $ratio;
        }

        return [$sequence, 'geometric, ratio '.$ratio];
    }

    /** @return array{list<int>, string} */
    private function quadratic(Rng $rng, int $terms): array
    {
        $a = $this->nonZero($rng, -3, 3);
        $b = $rng->intBetween(-6, 6);
        $c = $rng->intBetween(-8, 8);

        return [
            array_map(fn (int $n): int => $a * $n * $n + $b * $n + $c, range(1, $terms + 1)),
            'quadratic, second difference '.(2 * $a),
        ];
    }

    /** @return array{list<int>, string} */
    private function fibonacci(Rng $rng, int $terms): array
    {
        $sequence = [$rng->intBetween(1, 9), $rng->intBetween(1, 12)];

        for ($n = 2; $n <= $terms; $n++) {
            $sequence[] = $sequence[$n - 1] + $sequence[$n - 2];
        }

        return [$sequence, 'each term is the sum of the two before it'];
    }
}
