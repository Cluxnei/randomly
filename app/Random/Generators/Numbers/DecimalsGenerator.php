<?php

declare(strict_types=1);

namespace App\Random\Generators\Numbers;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Floats in a range, at a precision you choose — docs/04 §3.
 *
 * The whole generator is one line of arithmetic, and the interesting part is what
 * rounding does to it. Asking for two decimals between 0 and 1 is not "a random
 * real number, displayed shortly": it is a draw from 101 equally likely values,
 * and that is what the entropy figure reports. Drawing the integer grid directly
 * rather than rounding a uniform double is what makes that true — rounding
 * half-up would leave the two end values with half the width of every other one,
 * so 0.00 and 1.00 would each come up half as often and nobody would ever notice.
 */
final class DecimalsGenerator extends BaseGenerator
{
    /**
     * The largest grid a unique draw will materialise as an array.
     *
     * Above this, `range()` allocates megabytes to be shuffled for the sake of a
     * few hundred values — at precision 10 over a range of a million the grid has
     * 10^16 points, which is not an array at all. The redraw loop below covers
     * everything past here and is exact, just less direct.
     */
    private const SHUFFLEABLE_GRID = 200_000;

    public function key(): string
    {
        return 'numbers.decimals';
    }

    public function name(): string
    {
        return 'Decimals';
    }

    public function tagline(): string
    {
        return 'Floats in a range, at a precision you choose.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 10, min: 1, max: 1000)
            ->float('min', 'Minimum', default: 0.0, min: -1_000_000.0, max: 1_000_000.0, step: 0.1)
            ->float('max', 'Maximum', default: 1.0, min: -1_000_000.0, max: 1_000_000.0, step: 0.1)
            ->int('precision', 'Decimal places', default: 4, min: 0, max: 10, help: 'The grid the draw lands on, not just how it is printed: two places between 0 and 1 means 101 possible values.')
            ->bool('unique', 'No repeats', help: 'Draw without replacement from the grid the precision defines.')
            ->enum('sort', 'Order', [
                'none' => 'As drawn',
                'asc' => 'Ascending',
                'desc' => 'Descending',
            ], default: 'none');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // Swapped rather than rejected, exactly as numbers.integers does: a
        // dragged slider passes through every reversed range on its way.
        $lo = min($params->float('min'), $params->float('max'));
        $hi = max($params->float('min'), $params->float('max'));
        $precision = $params->int('precision');
        $step = 10 ** -$precision;

        /*
         * The number of grid points, computed in integer space.
         *
         * (hi − lo) / step is floating-point division of two floats that are
         * themselves inexact, and at precision 10 over a range of a million it
         * lands a hair under the true count — dropping the top value from the
         * draw. Rounding the multiplication instead keeps the grid symmetric.
         */
        $points = (int) round(($hi - $lo) * (10 ** $precision)) + 1;
        $count = $params->int('count');
        $unique = $params->bool('unique');

        // Uniqueness is a draw without replacement from the grid, so it cannot
        // deliver more values than the grid has points.
        if ($unique && $count > $points) {
            $count = $points;
        }

        $indices = $unique && $points <= self::SHUFFLEABLE_GRID
            ? $rng->sample(range(0, $points - 1), $count)
            : array_map(fn (): int => $rng->intBetween(0, $points - 1), range(1, $count));

        /*
         * Rejection sampling for a uniqueness request over a huge grid.
         *
         * range(0, 10^10) is not an array anyone can allocate, so the partial
         * Fisher-Yates above is only usable while the grid is small. Above that
         * the collision probability is tiny — at 200,000 points and a thousand
         * draws it is about half a percent per draw — but "tiny" is not "none",
         * so duplicates are redrawn rather than assumed away.
         */
        if ($unique && $points > self::SHUFFLEABLE_GRID) {
            $seen = [];
            foreach ($indices as $i => $index) {
                while (isset($seen[$index])) {
                    $index = $rng->intBetween(0, $points - 1);
                }
                $seen[$index] = true;
                $indices[$i] = $index;
            }
        }

        $values = array_map(fn (int $i): float => round($lo + $i * $step, $precision), $indices);

        $values = match ($params->string('sort')) {
            'asc' => $this->sorted($values),
            'desc' => array_reverse($this->sorted($values)),
            default => $values,
        };

        return new Result(
            value: $values,
            display: implode(', ', array_map(
                fn (float $v): string => number_format($v, $precision, '.', ''),
                $values,
            )),
            meta: [
                'grid_points' => $points,
                'step' => number_format($step, $precision, '.', ''),
                // log2 of the grid, per value — the honest count, and visibly
                // smaller than "a random double" would suggest.
                'entropy_out_bits' => round($count * log(max(2, $points), 2), 2),
                'rejections' => $rng->rejections(),
                'truncated' => $unique && $params->int('count') > $points,
                'note' => sprintf(
                    'Every value is drawn from a grid of %s equally likely points, not rounded from a continuous draw. Rounding would give the two ends half the width of every other value, and they would quietly come up half as often.',
                    number_format($points),
                ),
            ],
        );
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
