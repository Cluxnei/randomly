<?php

declare(strict_types=1);

namespace App\Random\Rng;

/**
 * Every random primitive in the project, drawn from a deterministic byte stream.
 *
 * Nothing here touches the clock, the network or global state, so a generator
 * given the same Rng always produces the same result. All impurity lives upstream
 * in the entropy pool.
 */
final class Rng
{
    private int $rejections = 0;

    public function __construct(private readonly HkdfStream $stream) {}

    public function bytes(int $n): string
    {
        return $this->stream->read($n);
    }

    public function uint32(): int
    {
        return unpack('N', $this->stream->read(4))[1];
    }

    /**
     * A uniform double in [0, 1).
     *
     * 53 bits is exactly the mantissa width of an IEEE-754 double, so this hits
     * every representable value in the interval with the right probability and
     * can never return 1.0. Reading 7 bytes keeps the intermediate inside PHP's
     * signed 64-bit int with room to spare.
     */
    public function float(): float
    {
        $v = 0;
        foreach (str_split($this->stream->read(7)) as $byte) {
            $v = ($v << 8) | ord($byte);
        }

        return ($v >> 3) * (2 ** -53);
    }

    /**
     * A uniform integer in [lo, hi], inclusive and unbiased.
     *
     * Bitmask rejection, the same approach PHP's own random_int() takes: draw the
     * smallest number of bits that covers the range and redraw when the value
     * overshoots. The tempting `x % n` is biased whenever n is not a power of two,
     * and on a six-sided die that bias is visible to the naked eye.
     *
     * Rejections are counted rather than hidden — the studio surfaces the number,
     * because watching a range like 1..100 discard roughly a fifth of its draws is
     * the clearest demonstration of what unbiased sampling costs.
     */
    public function intBetween(int $lo, int $hi): int
    {
        if ($lo > $hi) {
            throw new \InvalidArgumentException("Empty range: [{$lo}, {$hi}].");
        }
        if ($lo === $hi) {
            return $lo;
        }

        $range = $hi - $lo;

        // Two ways the subtraction escapes: a range that wraps past PHP_INT_MAX
        // comes back negative, and one that exceeds it outright promotes to a
        // float — PHP_INT_MIN to PHP_INT_MAX is 2^64−1 and arrives as
        // 1.8446744073709552E+19, which is not negative and would sail past a
        // sign check into a mask built from a float.
        if (! is_int($range) || $range < 0) {
            throw new \InvalidArgumentException("Range [{$lo}, {$hi}] overflows a signed 64-bit integer.");
        }

        $bits = 0;
        for ($r = $range; $r > 0; $r >>= 1) {
            $bits++;
        }
        $bytes = intdiv($bits + 7, 8);

        /*
         * At 63 bits the obvious mask stops being an integer.
         *
         * `1 << 63` is PHP_INT_MIN, and subtracting one from it promotes the whole
         * expression to a double — after which `$v & $mask` converts that double
         * back in a way that no longer masks anything. Measured on a 63-bit range:
         * 272 draws out of 500 landed outside the requested bounds, silently.
         *
         * An earlier guard here tested the *range* against PHP_INT_MAX, which was
         * the wrong quantity: the mask breaks at 63 bits, and a range needs only
         * to exceed 2^62 to get there — far below the ceiling being checked.
         * PHP_INT_MAX *is* 2^63 − 1, so at that width it is exactly the mask
         * wanted. Nothing wider than 63 bits can be reached: a 64-bit range does
         * not fit in a signed integer and is rejected above.
         */
        $mask = $bits >= 63 ? PHP_INT_MAX : (1 << $bits) - 1;

        while (true) {
            $v = 0;
            foreach (str_split($this->stream->read($bytes)) as $byte) {
                $v = ($v << 8) | ord($byte);
            }
            $v &= $mask;

            if ($v <= $range) {
                return $lo + $v;
            }

            $this->rejections++;
        }
    }

    public function bool(float $p = 0.5): bool
    {
        return $this->float() < $p;
    }

    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Cannot pick from an empty set.');
        }
        $values = array_values($items);

        return $values[$this->intBetween(0, count($values) - 1)];
    }

    /**
     * Fisher-Yates (Durstenfeld). Each of the n! permutations is equally likely,
     * which holds only because intBetween() above is unbiased. The common broken
     * variant draws j from the full range every pass and is badly skewed.
     */
    public function shuffle(array $items): array
    {
        $a = array_values($items);
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = $this->intBetween(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }

        return $a;
    }

    /** k items without replacement, via a partial shuffle — O(k), not O(n). */
    public function sample(array $items, int $k): array
    {
        $a = array_values($items);
        $n = count($a);
        if ($k > $n) {
            throw new \InvalidArgumentException("Cannot draw {$k} distinct items from {$n}.");
        }

        for ($i = 0; $i < $k; $i++) {
            $j = $this->intBetween($i, $n - 1);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }

        return array_slice($a, 0, $k);
    }

    /** Linear-scan weighted choice. Fine below a few hundred items; the alias table lands with the loaded-dice generator. */
    public function weighted(array $items, array $weights): mixed
    {
        $values = array_values($items);
        $w = array_values($weights);
        $total = array_sum($w);

        if ($total <= 0) {
            throw new \InvalidArgumentException('Weights must sum to something positive.');
        }

        $target = $this->float() * $total;
        foreach ($values as $i => $value) {
            $target -= $w[$i];
            if ($target < 0) {
                return $value;
            }
        }

        return end($values);
    }

    /**
     * Box-Muller, polar form. Returns one of the pair and caches the other.
     *
     * u1 is drawn from (0, 1] rather than [0, 1) — ln(0) is negative infinity and
     * would poison the result.
     */
    private ?float $spare = null;

    public function gaussian(float $mu = 0.0, float $sigma = 1.0): float
    {
        if ($this->spare !== null) {
            $z = $this->spare;
            $this->spare = null;

            return $mu + $sigma * $z;
        }

        $u1 = 1.0 - $this->float();
        $u2 = $this->float();
        $r = sqrt(-2.0 * log($u1));

        $this->spare = $r * sin(2 * M_PI * $u2);

        return $mu + $sigma * $r * cos(2 * M_PI * $u2);
    }

    public function exponential(float $lambda = 1.0): float
    {
        return -log(1.0 - $this->float()) / $lambda;
    }

    public function rejections(): int
    {
        return $this->rejections;
    }

    public function bytesRead(): int
    {
        return $this->stream->bytesRead();
    }
}
