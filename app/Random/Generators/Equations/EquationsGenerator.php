<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Problem;
use App\Random\Equations\ProblemSet;
use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * What every generator in the Equations module has in common.
 *
 * The shape is deliberately narrow: a generator produces a list of Problems and
 * nothing else. Turning that list into a Result, a display string, a LaTeX
 * payload for the browser and a printable worksheet all happen in one place, so
 * a new generator is a `problems()` method and a schema.
 *
 * It also means the tests can reach the problems as structure rather than as
 * printed text, which is what makes "substitute the stated answer back into the
 * stated problem and check it comes out true" a test that can actually be
 * written.
 */
abstract class EquationsGenerator extends BaseGenerator
{
    public function module(): Module
    {
        return Module::Equations;
    }

    public function renderer(): Renderer
    {
        return Renderer::Math;
    }

    /** @return list<Problem> */
    abstract public function problems(Rng $rng, Params $params): array;

    public function generate(Rng $rng, Params $params): Result
    {
        $problems = $this->problems($rng, $params);

        return ProblemSet::result($problems, $this->meta($params, $problems));
    }

    /** @param list<Problem> $problems */
    protected function meta(Params $params, array $problems): array
    {
        return [];
    }

    /** A non-zero draw, for the coefficients that would collapse the problem at zero. */
    protected function nonZero(Rng $rng, int $lo, int $hi): int
    {
        $value = $rng->intBetween($lo, $hi);

        // Nudging rather than redrawing keeps the stream consumption fixed per
        // problem, so problem n does not shift depending on what problem n-1 drew.
        return $value === 0 ? ($hi > 0 ? $hi : $lo) : $value;
    }
}
