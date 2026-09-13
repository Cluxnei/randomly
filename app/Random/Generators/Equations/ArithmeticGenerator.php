<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Binary;
use App\Random\Equations\Expr;
use App\Random\Equations\Problem;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Rng\Rng;

/**
 * Mental arithmetic drills.
 *
 * The only interesting decision here is division, and it is the module's whole
 * thesis in one line: rather than draw `a ÷ b` and hope it comes out even, draw
 * the *quotient* and the divisor and multiply them to get the dividend. Backward
 * construction, three lines of it, and the alternative — rejection-sampling
 * pairs until one divides — would burn an unbounded amount of the seed stream to
 * arrive at a worse distribution.
 */
final class ArithmeticGenerator extends EquationsGenerator
{
    public function key(): string
    {
        return 'equations.arithmetic';
    }

    public function name(): string
    {
        return 'Mental Math';
    }

    public function tagline(): string
    {
        return 'Drills at a digit count and operation set you pick, and division always comes out even.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('operations', 'Operations', [
                'add' => 'Addition',
                'sub' => 'Subtraction',
                'mul' => 'Multiplication',
                'div' => 'Division',
                'add_sub' => 'Addition and subtraction',
                'mul_div' => 'Multiplication and division',
                'all' => 'All four',
            ], default: 'all')
            ->int('digits', 'Digits', default: 2, min: 1, max: 4, help: 'How large the operands get.')
            ->int('count', 'How many', default: 10, min: 1, max: 100)
            ->bool('negatives', 'Allow negatives', help: 'Lets a subtraction go below zero, and operands turn negative.');
    }

    public function problems(Rng $rng, Params $params): array
    {
        $operations = $this->operations($params->string('operations'));
        $digits = $params->int('digits');
        $negatives = $params->bool('negatives');

        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = $this->problem($rng, $rng->pick($operations), $digits, $negatives);
        }

        return $problems;
    }

    /** @return list<string> */
    private function operations(string $choice): array
    {
        return match ($choice) {
            'add_sub' => ['+', '-'],
            'mul_div' => ['*', '/'],
            'add' => ['+'],
            'sub' => ['-'],
            'mul' => ['*'],
            'div' => ['/'],
            default => ['+', '-', '*', '/'],
        };
    }

    private function problem(Rng $rng, string $operation, int $digits, bool $negatives): Problem
    {
        [$a, $b] = match ($operation) {
            // Multiplication and division scale their second operand down a
            // digit. 4-digit × 4-digit is not mental arithmetic, it is long
            // multiplication, and the slider would stop meaning what it says.
            '*' => [$this->operand($rng, $digits), $this->operand($rng, max(1, $digits - 1))],
            '/' => $this->division($rng, $digits),
            default => [$this->operand($rng, $digits), $this->operand($rng, $digits)],
        };

        if ($operation === '-' && ! $negatives && $b > $a) {
            [$a, $b] = [$b, $a];
        }

        if ($negatives && $operation !== '/' && $rng->bool(0.3)) {
            $a = -$a;
        }

        $node = new Binary($operation, Expr::n($a), Expr::n($b));

        return Problem::of($node, Expr::n($node->evaluate()), check: ['expression' => $node]);
    }

    /**
     * The backward half: pick what the answer should be, then build the question
     * that has it.
     *
     * @return array{int, int} dividend and divisor
     */
    private function division(Rng $rng, int $digits): array
    {
        $divisor = $this->operand($rng, max(1, $digits - 1));
        $quotient = $this->operand($rng, $digits);

        return [$divisor * $quotient, $divisor];
    }

    /** A positive operand of exactly the requested width, never 0 or 1. */
    private function operand(Rng $rng, int $digits): int
    {
        return $digits === 1
            ? $rng->intBetween(2, 9)
            : $rng->intBetween(10 ** ($digits - 1), 10 ** $digits - 1);
    }
}
