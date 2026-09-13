<?php

declare(strict_types=1);

namespace App\Random\Equations;

use App\Random\Generators\Result;

/**
 * The one place a list of problems becomes a Result.
 *
 * Centralised so the worksheet, the studio and the API can rely on the shape
 * without every generator having to remember it — and so that `latex` lands in
 * the meta payload consistently, which is the only channel the browser renderer
 * reads.
 */
final class ProblemSet
{
    /**
     * @param  list<Problem>  $problems
     */
    public static function result(array $problems, array $meta = []): Result
    {
        return new Result(
            value: [
                'problems' => array_map(fn (Problem $p): array => $p->toArray(), $problems),
                'count' => count($problems),
            ],
            display: self::display($problems),
            meta: [
                // Consumed by resources/js/math.js. It is deliberately not part
                // of `display`: the clipboard wants the plain form, and a page
                // full of \frac{}{} pasted into a chat window helps nobody.
                'latex' => array_map(
                    fn (Problem $p): array => [$p->promptLatex, $p->answerLatex],
                    $problems,
                ),
                'problems' => count($problems),
                ...$meta,
            ],
        );
    }

    /** @param list<Problem> $problems */
    public static function display(array $problems): string
    {
        if (count($problems) === 1) {
            return trim($problems[0]->prompt.'   →   '.$problems[0]->answer);
        }

        $width = strlen((string) count($problems));
        $lines = [];

        foreach ($problems as $index => $problem) {
            $number = str_pad((string) ($index + 1), $width, ' ', STR_PAD_LEFT);
            $lines[] = "{$number}. ".$problem->prompt.'   →   '.$problem->answer;
        }

        return implode("\n", $lines);
    }
}
