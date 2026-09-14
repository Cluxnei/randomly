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
 * A random instant inside a window — docs/04 §3.
 *
 * The interesting constraint is the one the contract imposes: `generate()` may
 * not read the clock. "A random time in the next hour" is the obvious feature and
 * it is exactly what cannot be built here, because a generator that calls `now()`
 * returns something different on every replay and every permalink to it rots
 * within the hour. So the window is a *parameter*: two instants the caller names,
 * and a uniform draw between them.
 *
 * That also makes the window part of the seed fingerprint, which is the property
 * that makes a shared link mean anything — the same seed and the same window give
 * the same moment in 2031 as they do today.
 *
 * The dates are parsed strictly for the same reason. `new DateTimeImmutable($s)`
 * happily accepts "now", "next tuesday" and "+3 days", every one of which reads
 * the clock through the back door and would make this generator impure while
 * looking entirely innocent.
 */
final class TimestampGenerator extends BaseGenerator
{
    /**
     * The default window: the whole of the 21st century, in UTC.
     *
     * A literal rather than "the last year", for the reason in the class note —
     * a default that moves is a default that reads the clock.
     */
    private const DEFAULT_FROM = '2000-01-01T00:00:00';

    private const DEFAULT_TO = '2100-01-01T00:00:00';

    /** Accepted input shapes, strictest first. Anything else falls back to the defaults. */
    private const FORMATS = ['Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d'];

    public const GRANULARITIES = [
        'second' => 'Second',
        'minute' => 'Minute',
        'hour' => 'Hour',
        'day' => 'Day',
    ];

    public function key(): string
    {
        return 'numbers.timestamp';
    }

    public function name(): string
    {
        return 'Moments';
    }

    public function tagline(): string
    {
        return 'A random instant inside a window you define.';
    }

    public function module(): Module
    {
        return Module::Numbers;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 5, min: 1, max: 500)
            ->string('from', 'From (UTC)', default: self::DEFAULT_FROM, max: 32, help: 'YYYY-MM-DD, optionally with a time. Parsed strictly — “now” and “next friday” are rejected, because a generator that reads the clock cannot be replayed.')
            ->string('to', 'To (UTC)', default: self::DEFAULT_TO, max: 32)
            ->enum('granularity', 'Round to the nearest', self::GRANULARITIES, default: 'second')
            ->enum('format', 'Format', [
                'iso8601' => 'ISO 8601',
                'unix' => 'Unix seconds',
                'rfc2822' => 'RFC 2822',
                'date' => 'Date only',
                'human' => 'Readable',
            ], default: 'iso8601')
            ->enum('sort', 'Order', [
                'none' => 'As drawn',
                'asc' => 'Earliest first',
                'desc' => 'Latest first',
            ], default: 'none');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $from = $this->parse($params->string('from'));
        $to = $this->parse($params->string('to'));

        // A half-typed date is a user mid-edit, not an error state — the window
        // falls back to its default and the meta says it did, so nobody is left
        // wondering why "01/03/2024" produced dates in 2073.
        $unparsed = $from === null || $to === null;
        $from ??= $this->parse(self::DEFAULT_FROM);
        $to ??= $this->parse(self::DEFAULT_TO);

        // Reversed windows get swapped, the same way numbers.integers swaps a
        // reversed range: somebody editing a date field passes through every
        // invalid intermediate state on the way to a valid one.
        [$lo, $hi] = $from <= $to ? [$from, $to] : [$to, $from];

        $step = $this->stepSeconds($params->string('granularity', 'second'));

        // Snap the window to the granularity before drawing, not after. Rounding
        // afterwards would let a draw round *outside* the window, and would give
        // the two boundary slots half the width of every other one.
        $loStep = (int) ceil($lo / $step);
        $hiStep = (int) floor($hi / $step);
        $slots = max(1, $hiStep - $loStep + 1);

        $count = $params->int('count');
        $stamps = [];

        for ($i = 0; $i < $count; $i++) {
            $stamps[] = ($loStep + $rng->intBetween(0, $slots - 1)) * $step;
        }

        $stamps = match ($params->string('sort')) {
            'asc' => $this->sorted($stamps),
            'desc' => array_reverse($this->sorted($stamps)),
            default => $stamps,
        };

        $format = $params->string('format', 'iso8601');
        $rendered = array_map(fn (int $t): string => $this->render($t, $format), $stamps);

        return new Result(
            value: array_map(fn (int $t): array => [
                'unix' => $t,
                'iso' => $this->render($t, 'iso8601'),
            ], $stamps),
            display: implode(PHP_EOL, $rendered),
            meta: [
                'window_from' => $this->render($lo, 'iso8601'),
                'window_to' => $this->render($hi, 'iso8601'),
                'window_days' => round(($hi - $lo) / 86400, 2),
                'slots' => $slots,
                'granularity' => self::GRANULARITIES[$params->string('granularity', 'second')],
                'entropy_out_bits' => round($count * log(max(2, $slots), 2), 2),
                'rejections' => $rng->rejections(),
                'window_understood' => ! $unparsed,
                'note' => 'The window is a parameter, never the clock. That is what lets this one be replayed: a link shared today picks the same instant when it is opened next year, which a generator built on “the next hour” could never promise.',
            ],
        );
    }

    /**
     * Strict parsing, falling back to the default rather than throwing.
     *
     * createFromFormat with a leading `!` zeroes the fields the format does not
     * mention, so "2024-03-01" means midnight rather than "midnight’s date with
     * the current time attached" — which is the clock leaking in through a
     * default, and precisely the impurity this generator is built to avoid.
     */
    private function parse(string $input): ?int
    {
        $utc = new \DateTimeZone('UTC');
        $input = trim($input);

        foreach (self::FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $input, $utc);
            $errors = \DateTimeImmutable::getLastErrors();

            // Warnings count as failures here. "2024-02-31" parses with a warning
            // and silently rolls over to 2 March, which is a date the user did not
            // ask for arriving without a word said about it.
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);

            if ($parsed !== false && $clean) {
                return $parsed->getTimestamp();
            }
        }

        return null;
    }

    private function stepSeconds(string $granularity): int
    {
        return match ($granularity) {
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
            default => 1,
        };
    }

    private function render(int $timestamp, string $format): string
    {
        $moment = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));

        return match ($format) {
            'unix' => (string) $timestamp,
            'rfc2822' => $moment->format(\DateTimeInterface::RFC2822),
            'date' => $moment->format('Y-m-d'),
            'human' => $moment->format('l, j F Y \a\t H:i \U\T\C'),
            default => $moment->format('Y-m-d\TH:i:s\Z'),
        };
    }

    private function sorted(array $stamps): array
    {
        sort($stamps);

        return $stamps;
    }
}
