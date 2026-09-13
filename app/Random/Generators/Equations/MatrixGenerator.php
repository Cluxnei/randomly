<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Matrix;
use App\Random\Equations\Problem;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Rng\Rng;

/**
 * Matrices that have the property they are labelled with.
 *
 * Every kind here except the plain random one is built rather than filtered.
 * `Rᵢ += k·Rⱼ` leaves the determinant exactly where it was and every entry an
 * integer, so starting from `diag(d, 1, …, 1)` and applying a dozen of them
 * produces a matrix that looks arbitrary and has determinant `d` — no search, no
 * rejection, and no floating-point determinant to round off at the end.
 *
 * Positive-definite is the same idea through Cholesky: `A = L·Lᵀ` with a
 * strictly positive diagonal on L is positive-definite because `xᵀAx = |Lᵀx|²`,
 * which is a proof rather than a test.
 */
final class MatrixGenerator extends EquationsGenerator
{
    public function key(): string
    {
        return 'equations.matrix';
    }

    public function name(): string
    {
        return 'Matrices';
    }

    public function tagline(): string
    {
        return 'Any, invertible, symmetric or positive-definite — constructed, not rejection-sampled.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('kind', 'Kind', [
                'any' => 'Any entries',
                'invertible' => 'Invertible (det ±1)',
                'determinant' => 'A chosen determinant',
                'symmetric' => 'Symmetric',
                'spd' => 'Symmetric positive-definite',
            ], default: 'any')
            ->int('rows', 'Rows', default: 3, min: 2, max: 5)
            ->int('cols', 'Columns', default: 3, min: 2, max: 5, help: 'Ignored by the kinds that have to be square.')
            ->int('determinant', 'Determinant', default: 6, min: -12, max: 12)
            ->int('range', 'Entries within ±', default: 6, min: 1, max: 9)
            ->int('count', 'How many', default: 1, min: 1, max: 12);
    }

    public function problems(Rng $rng, Params $params): array
    {
        $kind = $params->string('kind');
        $rows = $params->int('rows');

        // Every kind but the plain one is defined by a determinant or by
        // symmetry, and neither exists off the square. Clamping is kinder than
        // refusing: the panel says so, and the result reports what it did.
        $cols = $kind === 'any' ? $params->int('cols') : $rows;

        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = $this->problem($rng, $kind, $rows, $cols, $params);
        }

        return $problems;
    }

    private function problem(Rng $rng, string $kind, int $rows, int $cols, Params $params): Problem
    {
        [$matrix, $note] = match ($kind) {
            'invertible' => [Matrix::unimodular($rng, $rows), 'invertible, with an integer inverse'],
            'determinant' => [Matrix::withDeterminant($rng, $rows, $params->int('determinant')), 'built to a chosen determinant'],
            'symmetric' => [Matrix::symmetric($rng, $rows, $params->int('range')), 'symmetric'],
            'spd' => [Matrix::positiveDefinite($rng, $rows)[0], 'symmetric positive-definite'],
            default => [Matrix::random($rng, $rows, $cols, $params->int('range')), 'unconstrained'],
        };

        $square = count($matrix) === count($matrix[0]);
        $determinant = $square ? Matrix::determinant($matrix) : null;

        $answer = $square
            ? 'det = '.$determinant.' — '.$note
            : $rows.'×'.$cols.' — not square, so no determinant';

        return new Problem(
            prompt: Matrix::toPlain($matrix),
            promptLatex: Matrix::toLatex($matrix),
            answer: $answer,
            answerLatex: $square ? '\det = '.$determinant : $rows.'\times'.$cols,
            extra: array_filter([
                'matrix' => $matrix,
                'determinant' => $determinant,
                'positive_definite' => $kind === 'spd' ? true : null,
            ], fn ($v) => $v !== null),
            check: ['matrix' => $matrix, 'kind' => $kind, 'determinant' => $determinant],
        );
    }

    protected function meta(Params $params, array $problems): array
    {
        return ['kind' => $params->string('kind')];
    }
}
