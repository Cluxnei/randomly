<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * True statements, and the probe points that prove them true.
 *
 * The True-or-False generator does not trust its own corruption step. It builds
 * a statement — corrupted or not — and then *verifies it numerically*, and what
 * it prints as the answer is the verdict of that check rather than a note of
 * what it did. That matters because corruption is not reliably destructive:
 * flipping `cos(2x) = 1 − 2sin²x` into `cos(2x) = 2cos²x − 1` produces a
 * different statement that happens to be equally true, and a generator that
 * announced "false" there would be teaching people something wrong.
 */
final class Identities
{
    public const TRIG = 'trig';

    public const LOG = 'log';

    /**
     * Angles chosen to sit well clear of tan's asymptotes, and to be
     * uncooperative: probing at 0 and π/6 would pass a great many statements
     * that are not identities at all.
     */
    private const TRIG_PROBES = [0.37, 0.83, 1.21, 2.05, -0.62, 2.71];

    /** Strictly positive, and never 1 — ln 1 = 0 hides sign errors. */
    private const LOG_PROBES = [0.7, 1.4, 2.3, 3.9, 5.5, 0.35];

    /**
     * @return list<array{key: string, lhs: Node, rhs: Node, domain: string}>
     */
    public static function all(string $topic = 'mixed'): array
    {
        $identities = [...self::trigonometric(), ...self::logarithmic()];

        if ($topic === 'mixed') {
            return $identities;
        }

        return array_values(array_filter(
            $identities,
            fn (array $identity): bool => $identity['domain'] === $topic,
        ));
    }

    /** @return list<array{key: string, lhs: Node, rhs: Node, domain: string}> */
    private static function trigonometric(): array
    {
        $x = Expr::v('x');
        $a = Expr::v('a');
        $b = Expr::v('b');
        $two = fn (Node $n): Node => Expr::mul(Expr::n(2), $n);
        $sq = fn (Node $n): Node => Expr::pow($n, Expr::n(2));

        return array_map(fn (array $row): array => [...$row, 'domain' => self::TRIG], [
            [
                'key' => 'pythagorean',
                'lhs' => Expr::add($sq(Expr::sin($x)), $sq(Expr::cos($x))),
                'rhs' => Expr::n(1),
            ],
            [
                'key' => 'sin-double',
                'lhs' => Expr::sin($two($x)),
                'rhs' => Expr::mul(Expr::n(2), Expr::sin($x), Expr::cos($x)),
            ],
            [
                'key' => 'cos-double-difference',
                'lhs' => Expr::cos($two($x)),
                'rhs' => Expr::sub($sq(Expr::cos($x)), $sq(Expr::sin($x))),
            ],
            [
                'key' => 'cos-double-sin',
                'lhs' => Expr::cos($two($x)),
                'rhs' => Expr::sub(Expr::n(1), Expr::mul(Expr::n(2), $sq(Expr::sin($x)))),
            ],
            [
                'key' => 'cos-double-cos',
                'lhs' => Expr::cos($two($x)),
                'rhs' => Expr::sub(Expr::mul(Expr::n(2), $sq(Expr::cos($x))), Expr::n(1)),
            ],
            [
                'key' => 'sin-sum',
                'lhs' => Expr::sin(Expr::add($a, $b)),
                'rhs' => Expr::add(
                    Expr::mul(Expr::sin($a), Expr::cos($b)),
                    Expr::mul(Expr::cos($a), Expr::sin($b)),
                ),
            ],
            [
                'key' => 'sin-difference',
                'lhs' => Expr::sin(Expr::sub($a, $b)),
                'rhs' => Expr::sub(
                    Expr::mul(Expr::sin($a), Expr::cos($b)),
                    Expr::mul(Expr::cos($a), Expr::sin($b)),
                ),
            ],
            [
                'key' => 'cos-sum',
                'lhs' => Expr::cos(Expr::add($a, $b)),
                'rhs' => Expr::sub(
                    Expr::mul(Expr::cos($a), Expr::cos($b)),
                    Expr::mul(Expr::sin($a), Expr::sin($b)),
                ),
            ],
            [
                'key' => 'cos-difference',
                'lhs' => Expr::cos(Expr::sub($a, $b)),
                'rhs' => Expr::add(
                    Expr::mul(Expr::cos($a), Expr::cos($b)),
                    Expr::mul(Expr::sin($a), Expr::sin($b)),
                ),
            ],
            [
                'key' => 'tan-definition',
                'lhs' => Expr::tan($x),
                'rhs' => Expr::div(Expr::sin($x), Expr::cos($x)),
            ],
            [
                'key' => 'tan-double',
                'lhs' => Expr::tan($two($x)),
                'rhs' => Expr::div(
                    Expr::mul(Expr::n(2), Expr::tan($x)),
                    Expr::sub(Expr::n(1), $sq(Expr::tan($x))),
                ),
            ],
            [
                'key' => 'pythagorean-tan',
                'lhs' => Expr::add(Expr::n(1), $sq(Expr::tan($x))),
                'rhs' => Expr::div(Expr::n(1), $sq(Expr::cos($x))),
            ],
            [
                'key' => 'sin-triple',
                'lhs' => Expr::sin(Expr::mul(Expr::n(3), $x)),
                'rhs' => Expr::sub(
                    Expr::mul(Expr::n(3), Expr::sin($x)),
                    Expr::mul(Expr::n(4), Expr::pow(Expr::sin($x), Expr::n(3))),
                ),
            ],
            [
                'key' => 'cos-triple',
                'lhs' => Expr::cos(Expr::mul(Expr::n(3), $x)),
                'rhs' => Expr::sub(
                    Expr::mul(Expr::n(4), Expr::pow(Expr::cos($x), Expr::n(3))),
                    Expr::mul(Expr::n(3), Expr::cos($x)),
                ),
            ],
            [
                'key' => 'cofunction',
                'lhs' => Expr::sin($x),
                'rhs' => Expr::cos(Expr::sub(Expr::div(Expr::pi(), Expr::n(2)), $x)),
            ],
            [
                'key' => 'product-to-sum',
                'lhs' => Expr::mul(Expr::n(2), Expr::sin($a), Expr::cos($b)),
                'rhs' => Expr::add(Expr::sin(Expr::add($a, $b)), Expr::sin(Expr::sub($a, $b))),
            ],
        ]);
    }

    /** @return list<array{key: string, lhs: Node, rhs: Node, domain: string}> */
    private static function logarithmic(): array
    {
        $a = Expr::v('a');
        $b = Expr::v('b');

        return array_map(fn (array $row): array => [...$row, 'domain' => self::LOG], [
            [
                'key' => 'log-product',
                'lhs' => Expr::ln(Expr::mul($a, $b)),
                'rhs' => Expr::add(Expr::ln($a), Expr::ln($b)),
            ],
            [
                'key' => 'log-quotient',
                'lhs' => Expr::ln(Expr::div($a, $b)),
                'rhs' => Expr::sub(Expr::ln($a), Expr::ln($b)),
            ],
            [
                'key' => 'log-power',
                'lhs' => Expr::ln(Expr::pow($a, Expr::n(3))),
                'rhs' => Expr::mul(Expr::n(3), Expr::ln($a)),
            ],
            [
                'key' => 'log-reciprocal',
                'lhs' => Expr::ln(Expr::div(Expr::n(1), $a)),
                'rhs' => Expr::neg(Expr::ln($a)),
            ],
            [
                'key' => 'log-sqrt',
                'lhs' => Expr::ln(Expr::sqrt($a)),
                'rhs' => Expr::div(Expr::ln($a), Expr::n(2)),
            ],
            [
                'key' => 'exp-sum',
                'lhs' => Expr::exp(Expr::add($a, $b)),
                'rhs' => Expr::mul(Expr::exp($a), Expr::exp($b)),
            ],
            [
                'key' => 'exp-difference',
                'lhs' => Expr::exp(Expr::sub($a, $b)),
                'rhs' => Expr::div(Expr::exp($a), Expr::exp($b)),
            ],
            [
                'key' => 'exp-power',
                'lhs' => Expr::pow(Expr::exp($a), Expr::n(2)),
                'rhs' => Expr::exp(Expr::mul(Expr::n(2), $a)),
            ],
            [
                'key' => 'log-of-exp',
                'lhs' => Expr::ln(Expr::exp($a)),
                'rhs' => $a,
            ],
            [
                'key' => 'change-of-base',
                'lhs' => Expr::div(Expr::ln($a), Expr::ln(Expr::n(2))),
                'rhs' => Expr::div(Expr::ln(Expr::pow($a, Expr::n(3))), Expr::mul(Expr::n(3), Expr::ln(Expr::n(2)))),
            ],
        ]);
    }

    /**
     * Does this statement actually hold?
     *
     * Relative rather than absolute tolerance, because tan(2x) near its
     * asymptote legitimately reaches the thousands and an absolute epsilon would
     * call a true identity false there. Points where either side blows up are
     * dropped rather than failed — a pole is not a counterexample — but enough
     * of them have to survive for the verdict to mean anything.
     */
    public static function holds(Node $lhs, Node $rhs, string $domain): bool
    {
        $usable = 0;

        foreach (self::probes($domain) as $bindings) {
            $left = $lhs->evaluate($bindings);
            $right = $rhs->evaluate($bindings);

            if (! is_finite($left) || ! is_finite($right) || abs($left) > 1e6 || abs($right) > 1e6) {
                continue;
            }

            $usable++;

            if (abs($left - $right) > 1e-7 * max(1.0, abs($left), abs($right))) {
                return false;
            }
        }

        // Too few live points and we have proved nothing, so refuse to claim it.
        return $usable >= 3;
    }

    /**
     * Can this statement be judged at all?
     *
     * Corrupting the 2 in `ln 2` to −2 produces something that is not false, it
     * is undefined — NaN at every probe point — and printing it as a false
     * identity teaches nothing except that the generator is broken. A statement
     * that cannot be evaluated is thrown away rather than labelled.
     */
    public static function isEvaluable(Node $lhs, Node $rhs, string $domain): bool
    {
        $usable = 0;

        foreach (self::probes($domain) as $bindings) {
            $left = $lhs->evaluate($bindings);
            $right = $rhs->evaluate($bindings);

            if (is_finite($left) && is_finite($right) && abs($left) <= 1e6 && abs($right) <= 1e6) {
                $usable++;
            }
        }

        return $usable >= 3;
    }

    /** @return list<array<string, float>> */
    public static function probes(string $domain): array
    {
        $values = $domain === self::LOG ? self::LOG_PROBES : self::TRIG_PROBES;
        $sets = [];

        foreach ($values as $index => $value) {
            $sets[] = [
                'x' => $value,
                'a' => $value,
                // Offset so a and b are never equal: half the sum and difference
                // identities are indistinguishable from nonsense when they are.
                'b' => $values[($index + 2) % count($values)],
            ];
        }

        return $sets;
    }
}
