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
 * Standard tabletop dice notation, parsed properly.
 *
 *   4d6kh3+2   roll four six-sided dice, keep the highest three, add two
 *   2d20kl1    roll two d20 and keep the lowest — disadvantage
 *   6d6!       exploding: a maximum roll is kept and rolled again
 *
 * Every die is drawn through the unbiased path in Rng::intBetween(). That matters
 * more here than anywhere else in the project: a d20 drawn with `x % 20` favours
 * the low numbers, and a table full of people would eventually feel it.
 */
final class DiceGenerator extends BaseGenerator
{
    private const MAX_DICE = 200;

    private const MAX_EXPLOSIONS = 50;

    public function key(): string
    {
        return 'numbers.dice';
    }

    public function name(): string
    {
        return 'Dice';
    }

    public function tagline(): string
    {
        return 'Full dice notation, rolled without the bias a modulo would introduce.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->string('notation', 'Notation', default: '4d6kh3', max: 40, help: 'e.g. 3d6+2, 4d6kh3, 2d20kl1, 6d6!')
            ->int('rolls', 'How many rolls', default: 6, min: 1, max: 100);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $spec = $this->parse($params->string('notation', '4d6kh3'));
        $rolls = [];

        for ($i = 0; $i < $params->int('rolls'); $i++) {
            $rolls[] = $this->roll($rng, $spec);
        }

        $totals = array_column($rolls, 'total');

        return new Result(
            value: $rolls,
            display: implode(', ', $totals),
            meta: [
                'notation' => $spec['canonical'],
                'total' => array_sum($totals),
                'mean' => round(array_sum($totals) / max(1, count($totals)), 2),
                'lowest' => min($totals),
                'highest' => max($totals),
                'rejections' => $rng->rejections(),
            ],
        );
    }

    /**
     * @return array{count:int, sides:int, keep:?int, keepHigh:bool, explode:bool, modifier:int, canonical:string}
     */
    private function parse(string $notation): array
    {
        $clean = strtolower(preg_replace('/\s+/', '', $notation) ?? '');

        $matched = preg_match(
            '/^(\d*)d(\d+)(k([hl])(\d+))?(!)?([+-]\d+)?$/',
            $clean,
            $m,
        );

        if (! $matched) {
            // Unparseable input falls back to the most ordinary roll there is rather
            // than erroring — this field is typed into, and a half-finished
            // expression should not blow up the page mid-keystroke.
            return $this->parse('1d6');
        }

        $count = max(1, min((int) ($m[1] === '' ? 1 : $m[1]), self::MAX_DICE));
        $sides = max(2, min((int) $m[2], 1000));
        $keep = isset($m[5]) && $m[5] !== '' ? min((int) $m[5], $count) : null;

        return [
            'count' => $count,
            'sides' => $sides,
            'keep' => $keep,
            'keepHigh' => ($m[4] ?? 'h') === 'h',
            'explode' => ($m[6] ?? '') === '!',
            'modifier' => (int) ($m[7] ?? 0),
            'canonical' => sprintf(
                '%dd%d%s%s%s',
                $count,
                $sides,
                $keep !== null ? 'k'.(($m[4] ?? 'h') === 'h' ? 'h' : 'l').$keep : '',
                ($m[6] ?? '') === '!' ? '!' : '',
                ($m[7] ?? '') !== '' ? sprintf('%+d', (int) $m[7]) : '',
            ),
        ];
    }

    private function roll(Rng $rng, array $spec): array
    {
        $dice = [];

        for ($i = 0; $i < $spec['count']; $i++) {
            $value = $rng->intBetween(1, $spec['sides']);
            $dice[] = $value;

            // An exploding die that rolls its maximum is kept and rolled again.
            // The cap exists because a d2 explodes half the time and would otherwise
            // be a coin flip against an unbounded loop.
            if ($spec['explode']) {
                $explosions = 0;
                while ($value === $spec['sides'] && $explosions < self::MAX_EXPLOSIONS) {
                    $value = $rng->intBetween(1, $spec['sides']);
                    $dice[] = $value;
                    $explosions++;
                }
            }
        }

        $kept = $dice;

        if ($spec['keep'] !== null) {
            $sorted = $dice;
            rsort($sorted);
            $kept = $spec['keepHigh']
                ? array_slice($sorted, 0, $spec['keep'])
                : array_slice(array_reverse($sorted), 0, $spec['keep']);
        }

        return [
            'dice' => $dice,
            'kept' => $kept,
            'modifier' => $spec['modifier'],
            'total' => array_sum($kept) + $spec['modifier'],
        ];
    }
}
