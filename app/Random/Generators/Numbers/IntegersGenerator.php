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
 * The reference implementation of the Generator contract — the shape every other
 * generator in the project follows.
 */
final class IntegersGenerator extends BaseGenerator
{
    public function key(): string
    {
        return 'numbers.integers';
    }

    public function name(): string
    {
        return 'Integers';
    }

    public function tagline(): string
    {
        return 'Whole numbers in any range, drawn without the bias everyone else ships.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 10, min: 1, max: 1000)
            ->int('min', 'Minimum', default: 1, min: -1_000_000, max: 1_000_000)
            ->int('max', 'Maximum', default: 100, min: -1_000_000, max: 1_000_000)
            ->bool('unique', 'No repeats', help: 'Draw without replacement, like a lottery.')
            ->enum('sort', 'Order', [
                'none' => 'As drawn',
                'asc' => 'Ascending',
                'desc' => 'Descending',
            ], default: 'none');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // A dragged slider can put max below min; swap rather than error, since the
        // user's intent is unambiguous and a 422 mid-drag would be obnoxious.
        $lo = min($params->int('min'), $params->int('max'));
        $hi = max($params->int('min'), $params->int('max'));
        $span = $hi - $lo + 1;
        $count = $params->int('count');
        $unique = $params->bool('unique');

        if ($unique && $count > $span) {
            $count = $span;
        }

        $numbers = $unique
            ? $rng->sample(range($lo, $hi), $count)
            : array_map(fn () => $rng->intBetween($lo, $hi), range(1, $count));

        $numbers = match ($params->string('sort')) {
            'asc' => $this->sorted($numbers),
            'desc' => array_reverse($this->sorted($numbers)),
            default => $numbers,
        };

        return new Result(
            value: $numbers,
            display: implode(', ', $numbers),
            meta: [
                'entropy_out_bits' => round($this->entropyBits($span, $count, $unique), 2),
                'rejections' => $rng->rejections(),
                'truncated' => $unique && $params->int('count') > $span,
            ],
        );
    }

    private function sorted(array $numbers): array
    {
        sort($numbers);

        return $numbers;
    }

    /**
     * How much randomness the user actually walked away with.
     *
     * With replacement each draw is independent, so the outcome space is span^count.
     * Without replacement the draw is an ordered selection, so it is the falling
     * factorial — summed in log space because span! overflows immediately.
     */
    private function entropyBits(int $span, int $count, bool $unique): float
    {
        if (! $unique) {
            return $count * log($span, 2);
        }

        $bits = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $bits += log($span - $i, 2);
        }

        return $bits;
    }
}
