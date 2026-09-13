<?php

declare(strict_types=1);

namespace App\Random\Equations;

use App\Random\Rng\Rng;

/**
 * Integer matrices, built to have the properties they claim.
 *
 * The interesting constructions here are all the same trick as the rest of the
 * module: rather than draw a matrix and hope it is invertible, start from one
 * that provably is and apply operations that cannot break it. `Rᵢ += k·Rⱼ` adds
 * a multiple of one row to another, which leaves the determinant untouched and
 * every entry an integer — so a hundred of them turn the identity into something
 * that looks random while the determinant has never moved off 1.
 *
 * Everything is exact. Determinants go through Bareiss rather than Gaussian
 * elimination precisely so there is never a float to round: a generator that
 * prints "det = 6" about a matrix whose determinant is 5.999999999 has lied.
 */
final class Matrix
{
    /** How large an entry may get before a row operation is rolled back. */
    private const DEFAULT_ENTRY_LIMIT = 9;

    /**
     * The determinant, exactly, by fraction-free Gaussian elimination.
     *
     * Bareiss divides each pivot step by the previous pivot, and the division is
     * provably exact at every step, so an integer matrix stays an integer matrix
     * the whole way down and the answer has no rounding in it at all.
     *
     * @param  list<list<int>>  $m
     */
    public static function determinant(array $m): int
    {
        $n = count($m);

        if ($n === 0 || count($m[0]) !== $n) {
            throw new \InvalidArgumentException('The determinant wants a square matrix.');
        }

        $sign = 1;
        $previous = 1;

        for ($k = 0; $k < $n - 1; $k++) {
            if ($m[$k][$k] === 0) {
                $swap = null;

                for ($row = $k + 1; $row < $n; $row++) {
                    if ($m[$row][$k] !== 0) {
                        $swap = $row;
                        break;
                    }
                }

                if ($swap === null) {
                    return 0;
                }

                [$m[$k], $m[$swap]] = [$m[$swap], $m[$k]];
                $sign = -$sign;
            }

            for ($i = $k + 1; $i < $n; $i++) {
                for ($j = $k + 1; $j < $n; $j++) {
                    $m[$i][$j] = intdiv($m[$i][$j] * $m[$k][$k] - $m[$i][$k] * $m[$k][$j], $previous);
                }
            }

            $previous = $m[$k][$k];
        }

        return $sign * $m[$n - 1][$n - 1];
    }

    /**
     * Sylvester's criterion: symmetric with every leading principal minor
     * positive is exactly what positive-definite means, and since the minors are
     * integer determinants the test is exact rather than an eigenvalue estimate.
     *
     * @param  list<list<int>>  $m
     */
    public static function isPositiveDefinite(array $m): bool
    {
        if (! self::isSymmetric($m)) {
            return false;
        }

        for ($k = 1; $k <= count($m); $k++) {
            $minor = array_map(fn (array $row): array => array_slice($row, 0, $k), array_slice($m, 0, $k));

            if (self::determinant($minor) <= 0) {
                return false;
            }
        }

        return true;
    }

    /** @param list<list<int>> $m */
    public static function isSymmetric(array $m): bool
    {
        $n = count($m);

        for ($i = 0; $i < $n; $i++) {
            if (count($m[$i]) !== $n) {
                return false;
            }

            for ($j = $i + 1; $j < $n; $j++) {
                if ($m[$i][$j] !== $m[$j][$i]) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<list<int>> */
    public static function identity(int $n, int $scale = 1): array
    {
        $m = [];

        for ($i = 0; $i < $n; $i++) {
            $m[$i] = array_fill(0, $n, 0);
            $m[$i][$i] = $i === 0 ? $scale : 1;
        }

        return $m;
    }

    /**
     * A matrix with exactly the determinant asked for.
     *
     * diag(d, 1, …, 1) has determinant d to begin with; every row and column
     * operation applied afterwards preserves it. An operation that would push an
     * entry past the size limit is rolled back rather than clamped, because
     * clamping an entry is the one edit that would silently change the answer.
     *
     * @return list<list<int>>
     */
    public static function withDeterminant(Rng $rng, int $n, int $determinant, int $limit = self::DEFAULT_ENTRY_LIMIT): array
    {
        $m = self::identity($n, $determinant);
        $operations = 4 * $n + 4;

        for ($step = 0; $step < $operations; $step++) {
            $i = $rng->intBetween(0, $n - 1);
            $j = $rng->intBetween(0, $n - 1);

            if ($i === $j) {
                continue;
            }

            $k = $rng->pick([-2, -1, 1, 1, 2]);

            // Alternating rows and columns; using only rows leaves the column
            // structure of the diagonal visible, and a "random" matrix whose
            // first column is always (d, 0, 0) is not one.
            $candidate = $step % 2 === 0
                ? self::addRow($m, $i, $j, $k)
                : self::addColumn($m, $i, $j, $k);

            if (self::largestEntry($candidate) <= $limit) {
                $m = $candidate;
            }
        }

        return $m;
    }

    /** Determinant ±1, so the inverse is integral too. */
    public static function unimodular(Rng $rng, int $n, int $limit = self::DEFAULT_ENTRY_LIMIT): array
    {
        return self::withDeterminant($rng, $n, $rng->bool() ? 1 : -1, $limit);
    }

    /**
     * A = L·Lᵀ with L lower-triangular and its diagonal strictly positive.
     *
     * That is the Cholesky factorisation run backwards, and it is why the result
     * is positive-definite by construction rather than by rejection sampling:
     * xᵀAx = |Lᵀx|² > 0 for any x ≠ 0 exactly because L has no zero on its
     * diagonal.
     *
     * @return array{list<list<int>>, list<list<int>>} the matrix and its factor L
     */
    public static function positiveDefinite(Rng $rng, int $n): array
    {
        $l = [];

        for ($i = 0; $i < $n; $i++) {
            $l[$i] = array_fill(0, $n, 0);

            for ($j = 0; $j < $i; $j++) {
                $l[$i][$j] = $rng->intBetween(-2, 2);
            }

            $l[$i][$i] = $rng->intBetween(1, 3);
        }

        return [self::multiply($l, self::transpose($l)), $l];
    }

    /** @return list<list<int>> */
    public static function symmetric(Rng $rng, int $n, int $range): array
    {
        $m = array_fill(0, $n, array_fill(0, $n, 0));

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i; $j < $n; $j++) {
                $m[$i][$j] = $m[$j][$i] = $rng->intBetween(-$range, $range);
            }
        }

        return $m;
    }

    /** @return list<list<int>> */
    public static function random(Rng $rng, int $rows, int $cols, int $range): array
    {
        $m = [];

        for ($i = 0; $i < $rows; $i++) {
            $m[$i] = [];

            for ($j = 0; $j < $cols; $j++) {
                $m[$i][$j] = $rng->intBetween(-$range, $range);
            }
        }

        return $m;
    }

    /** @return list<list<int>> */
    public static function multiply(array $a, array $b): array
    {
        $out = [];

        foreach ($a as $i => $row) {
            $out[$i] = [];

            for ($j = 0; $j < count($b[0]); $j++) {
                $sum = 0;

                foreach ($row as $k => $value) {
                    $sum += $value * $b[$k][$j];
                }

                $out[$i][$j] = $sum;
            }
        }

        return $out;
    }

    /** @return list<int> */
    public static function apply(array $a, array $vector): array
    {
        return array_map(
            fn (array $row): int => (int) array_sum(array_map(
                fn (int $value, int $x): int => $value * $x,
                $row,
                $vector,
            )),
            $a,
        );
    }

    /** @return list<list<int>> */
    public static function transpose(array $a): array
    {
        return array_map(null, ...$a);
    }

    public static function largestEntry(array $m): int
    {
        return (int) max(array_map(fn (array $row): int => (int) max(array_map('abs', $row)), $m));
    }

    private static function addRow(array $m, int $i, int $j, int $k): array
    {
        foreach ($m[$j] as $column => $value) {
            $m[$i][$column] += $k * $value;
        }

        return $m;
    }

    private static function addColumn(array $m, int $i, int $j, int $k): array
    {
        foreach ($m as $row => $values) {
            $m[$row][$i] += $k * $values[$j];
        }

        return $m;
    }

    public static function toLatex(array $m): string
    {
        $rows = array_map(fn (array $row): string => implode(' & ', $row), $m);

        return '\begin{pmatrix}'.implode(' \\\\ ', $rows).'\end{pmatrix}';
    }

    /** Octave/MATLAB literal syntax — compact on one line, and pasteable. */
    public static function toPlain(array $m): string
    {
        return '['.implode('; ', array_map(
            fn (array $row): string => implode(', ', $row),
            $m,
        )).']';
    }
}
