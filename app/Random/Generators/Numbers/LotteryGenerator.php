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
 * Lottery lines, drawn without replacement, with the odds stated.
 *
 * The odds are not decoration. A lottery generator that hands back six numbers
 * and says nothing else is advertising: it presents the draw as a thing you might
 * win and quietly omits the only number that matters. So every result carries
 * 1/C(n, k) — times the bonus pool where there is one — and the median wait at a
 * line a week, which is the figure that actually lands.
 *
 * The draw itself is Rng::sample: a partial Fisher–Yates, so it is O(k) and
 * cannot repeat a number. The naive "draw until you get one you haven't seen"
 * loop is the usual implementation and is both slower and, if written with a
 * biased index, subtly non-uniform.
 */
final class LotteryGenerator extends BaseGenerator
{
    /**
     * The real games, as published.
     *
     * [label, main pool, main picks, bonus pool, bonus picks, bonus label]
     */
    private const GAMES = [
        'mega-sena' => ['Mega-Sena', 60, 6, 0, 0, ''],
        'powerball' => ['Powerball', 69, 5, 26, 1, 'Powerball'],
        'euromillions' => ['EuroMillions', 50, 5, 12, 2, 'Lucky Stars'],
    ];

    public function key(): string
    {
        return 'numbers.lottery';
    }

    public function name(): string
    {
        return 'Lottery';
    }

    public function tagline(): string
    {
        return 'Mega-Sena, Powerball and EuroMillions — with the odds printed on them.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('game', 'Game', [
                'mega-sena' => 'Mega-Sena (6 from 60)',
                'powerball' => 'Powerball (5 from 69 + 1 from 26)',
                'euromillions' => 'EuroMillions (5 from 50 + 2 from 12)',
                'custom' => 'Custom',
            ], default: 'mega-sena')
            ->int('lines', 'Lines', default: 5, min: 1, max: 20)
            // The custom bounds are not arbitrary. C(80,10) · C(50,3) is about
            // 3.2 × 10^16, which leaves three orders of magnitude of headroom under
            // PHP_INT_MAX — so the odds arithmetic below stays exact integer
            // arithmetic and never silently promotes to a float.
            ->int('pool', 'Custom: pool size', default: 60, min: 2, max: 80)
            ->int('picks', 'Custom: numbers drawn', default: 6, min: 1, max: 10)
            ->int('bonus_pool', 'Custom: bonus pool', default: 0, min: 0, max: 50)
            ->int('bonus_picks', 'Custom: bonus drawn', default: 0, min: 0, max: 3);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        [$label, $pool, $picks, $bonusPool, $bonusPicks, $bonusLabel] = $this->game($params);

        $lines = [];

        for ($i = 0; $i < $params->int('lines'); $i++) {
            $numbers = $rng->sample(range(1, $pool), $picks);
            sort($numbers);

            $bonus = [];
            if ($bonusPicks > 0) {
                $bonus = $rng->sample(range(1, $bonusPool), $bonusPicks);
                sort($bonus);
            }

            $lines[] = ['numbers' => $numbers, 'bonus' => $bonus];
        }

        $odds = $this->combinations($pool, $picks) * $this->combinations($bonusPool, $bonusPicks);
        $count = count($lines);

        return new Result(
            value: $lines,
            display: implode("\n", array_map($this->formatLine(...), $lines)),
            meta: [
                'game' => $label,
                'format' => $bonusPicks > 0
                    ? sprintf('%d from %d, plus %d from %d', $picks, $pool, $bonusPicks, $bonusPool)
                    : sprintf('%d from %d', $picks, $pool),
                'bonus_label' => $bonusLabel,
                'odds_one_in' => $odds,
                'odds' => '1 in '.number_format($odds),
                'odds_for_these_lines' => '1 in '.number_format((int) round($odds / $count)),
                // Every draw is independent, so the number of draws until a win is
                // geometric: the median is ln2 · odds, not odds. Quoting the mean
                // would overstate the wait by about 44%.
                'median_wait_years' => round($odds * M_LN2 / 52 / $count, 1),
                'entropy_out_bits' => round(log($odds, 2) * $count, 2),
                'note' => sprintf(
                    '%s: %s. Buying %s every week, there is an even chance of a jackpot after about %s years. Buying twice as many lines halves that and does nothing else — the odds are linear in the number of tickets, and no arrangement of numbers is luckier than another. The draw above is without replacement by partial Fisher–Yates, so no line can repeat a number.',
                    $label,
                    $picks.' numbers from '.$pool.($bonusPicks > 0 ? sprintf(' plus %d from %d, one line in %s', $bonusPicks, $bonusPool, number_format($odds)) : ', one line in '.number_format($odds)),
                    $count === 1 ? 'one line' : "these {$count} lines",
                    number_format($odds * M_LN2 / 52 / $count, 0),
                ),
            ],
        );
    }

    /** @return array{string, int, int, int, int, string} */
    private function game(Params $params): array
    {
        $key = $params->string('game', 'mega-sena');

        if (isset(self::GAMES[$key])) {
            return self::GAMES[$key];
        }

        $pool = $params->int('pool');
        $bonusPool = $params->int('bonus_pool');

        // A pool cannot give up more numbers than it holds, and a bonus draw with
        // an empty pool is no bonus draw at all. Clamped rather than rejected, on
        // the same reasoning as a reversed slider range.
        $picks = min($params->int('picks'), $pool);
        $bonusPicks = min($params->int('bonus_picks'), $bonusPool);

        return ['Custom', $pool, $picks, $bonusPool, $bonusPicks, $bonusPicks > 0 ? 'Bonus' : ''];
    }

    private function formatLine(array $line): string
    {
        $main = implode(' ', array_map(fn (int $n): string => sprintf('%02d', $n), $line['numbers']));

        if ($line['bonus'] === []) {
            return $main;
        }

        return $main.'  +  '.implode(' ', array_map(fn (int $n): string => sprintf('%02d', $n), $line['bonus']));
    }

    /**
     * C(n, k), exactly, in integers.
     *
     * The multiplicative form with a division at every step: after multiplying by
     * i consecutive integers the running product is divisible by i!, so intdiv is
     * exact at each iteration and the intermediate never grows past the answer
     * itself. Computing n!/(k!(n−k)!) directly would overflow at n = 21.
     */
    private function combinations(int $n, int $k): int
    {
        if ($k <= 0 || $k > $n) {
            return 1;
        }

        // C(n, k) = C(n, n−k), and the smaller k is the cheaper loop.
        $k = min($k, $n - $k);
        $result = 1;

        for ($i = 1; $i <= $k; $i++) {
            $result = intdiv($result * ($n - $k + $i), $i);
        }

        return $result;
    }
}
