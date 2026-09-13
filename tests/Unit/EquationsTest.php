<?php

declare(strict_types=1);

use App\Random\Entropy\Seed;
use App\Random\Equations\Expr;
use App\Random\Equations\Fmt;
use App\Random\Equations\Grammar;
use App\Random\Equations\Identities;
use App\Random\Equations\Matrix;
use App\Random\Equations\Node;
use App\Random\Equations\Problem;
use App\Random\Generators\Equations\EquationsGenerator;
use Symfony\Component\Process\Process;

/**
 * The Equations module's own tests. The generic suite in GeneratorsTest already
 * covers determinism, schema round-tripping and entropy use for everything in
 * the registry; what is left is the part only this module can be wrong about.
 *
 * The claim being defended throughout is the one from the module's brief: a
 * result must never contain a malformed or unsolvable problem. That is checkable
 * here in a way it almost never is elsewhere — the answer can be substituted
 * back into the question and the arithmetic done.
 */
function equationSeed(int $index): Seed
{
    return new Seed(substr(hash('sha256', "equations-{$index}", true), 0, Seed::BYTES), stubReceipt());
}

/** @return list<Problem> */
function equationProblems(string $key, array $input = [], int $seed = 0): array
{
    $generator = allGenerators()[$key];

    expect($generator)->toBeInstanceOf(EquationsGenerator::class);

    $params = $generator->schema()->coerce($input);

    return $generator->problems(
        equationSeed($seed)->rng($key, $generator->version(), $params->fingerprint()),
        $params,
    );
}

/** Every problem the given generator makes across many seeds, flattened. */
function equationSweep(string $key, array $input, int $seeds): array
{
    $all = [];

    for ($seed = 0; $seed < $seeds; $seed++) {
        $all = [...$all, ...equationProblems($key, $input, $seed)];
    }

    return $all;
}

/**
 * Parameter sets worth sweeping, per generator.
 *
 * Shared by the two tests that are looking for something a default run would
 * never show them: the noise check and the LaTeX check. The `-3x + 0` bug lived
 * only in the bracket tier with fractional solutions, which is not where any
 * default lands.
 */
function equationVariants(): array
{
    return [
        'equations.arithmetic' => [['operations' => 'div', 'digits' => 4], ['negatives' => true], ['operations' => 'all', 'digits' => 1]],
        'equations.expression' => [['functions' => 'full', 'variables' => 3, 'constants' => true, 'depth' => 5], ['functions' => 'roots'], ['functions' => 'none', 'variables' => 0, 'depth' => 4]],
        'equations.linear' => [['tier' => 'mixed'], ['tier' => 'brackets', 'integer_solutions' => false], ['tier' => 'two_sided', 'integer_solutions' => false], ['tier' => 'one_step', 'integer_solutions' => false]],
        'equations.quadratic' => [['roots' => 'mixed'], ['roots' => 'surd'], ['roots' => 'rational'], ['roots' => 'integer', 'monic' => true]],
        'equations.system' => [['size' => '2'], ['size' => '3']],
        'equations.calculus' => [['mode' => 'derivative', 'rule' => 'mixed'], ['mode' => 'integral', 'rule' => 'mixed']],
        'equations.matrix' => [['kind' => 'spd', 'rows' => 4], ['kind' => 'determinant'], ['kind' => 'any', 'rows' => 2, 'cols' => 5], ['kind' => 'symmetric']],
        'equations.identity' => [['topic' => 'trig'], ['topic' => 'log'], ['topic' => 'mixed', 'explain' => false]],
        'equations.sequence' => [['kind' => 'mixed'], ['kind' => 'geometric', 'terms' => 8]],
    ];
}

/* ── The evaluator ─────────────────────────────────────────────────────── */

it('evaluates an expression tree without eval()', function (): void {
    $tree = Expr::add(Expr::mul(Expr::n(3), Expr::pow(Expr::v('x'), Expr::n(2))), Expr::n(-7));

    expect($tree->evaluate(['x' => 4]))->toBe(41.0)
        ->and($tree->toPlain())->toBe('3x^2 - 7')
        ->and($tree->toLatex())->toBe('3x^{2} - 7');
});

it('refuses to evaluate a variable it was given no value for', function (): void {
    // Silently defaulting to zero would let a domain guard report that it had
    // probed a tree it never actually evaluated.
    expect(fn () => Expr::v('x')->evaluate([]))->toThrow(InvalidArgumentException::class);
});

it('applies only the cheap simplification rules', function (Node $input, string $expected): void {
    expect($input->simplify()->toPlain())->toBe($expected);
})->with([
    'x · 1' => [fn () => Expr::mul(Expr::v('x'), Expr::n(1)), 'x'],
    'x + 0' => [fn () => Expr::add(Expr::v('x'), Expr::n(0)), 'x'],
    'x ^ 1' => [fn () => Expr::pow(Expr::v('x'), Expr::n(1)), 'x'],
    'constant folding' => [fn () => Expr::mul(Expr::n(6), Expr::n(7)), '42'],
    'exact fractions survive folding' => [fn () => Expr::div(Expr::n(3), Expr::n(2)), '3/2'],
    'double negation' => [fn () => Expr::neg(Expr::neg(Expr::v('x'))), 'x'],
    'coefficients gather' => [fn () => Expr::mul(Expr::n(2), Expr::mul(Expr::v('x'), Expr::n(5))), '10x'],
    'sin is not folded to a decimal' => [fn () => Expr::sin(Expr::n(2)), 'sin(2)'],
    // Zero is not negative, so the rule that pulls a minus off a negative
    // subtree cannot see this one — and `-0` gives the machine away instantly.
    'negative zero' => [fn () => Expr::neg(Expr::n(0)), '0'],
    'a product that cancels to zero' => [fn () => Expr::mul(Expr::n(0), Expr::neg(Expr::v('x'))), '0'],
]);

it('brackets only where the grammar needs it', function (Node $tree, string $expected): void {
    expect($tree->toPlain())->toBe($expected);
})->with([
    // `((3+0)·1)/((2)^1)` is a valid expression and reads as noise. Printing is
    // where this module is most easily let down.
    'a sum inside a product' => [fn () => Expr::mul(Expr::n(2), Expr::add(Expr::v('x'), Expr::n(1))), '2(x + 1)'],
    'a product inside a sum' => [fn () => Expr::add(Expr::mul(Expr::n(2), Expr::v('x')), Expr::n(1)), '2x + 1'],
    'a subtracted difference' => [fn () => Expr::sub(Expr::v('a'), Expr::sub(Expr::v('b'), Expr::v('c'))), 'a - (b - c)'],
    'a negative term folds into the sign' => [fn () => Expr::add(Expr::v('x'), Expr::n(-4)), 'x - 4'],
    'a negative coefficient folds too' => [fn () => Expr::add(Expr::pow(Expr::v('x'), Expr::n(2)), Expr::mul(Expr::n(-5), Expr::v('x'))), 'x^2 - 5x'],
]);

it('only calls a tree negative when negating it really is flipping its sign', function (Node $tree, string $expected): void {
    // The invariant behind every `a + (-b)` printed as `a - b`. It is easy to
    // get wrong in a way no value test notices, because the tree keeps
    // evaluating correctly while the string beside it lies: `(-10)^(-1)` briefly
    // printed as `10^(-1)`, which is a different number for every even exponent.
    expect($tree->toPlain())->toBe($expected);

    if ($tree->isNegative()) {
        foreach ([0.7, 1.9, -1.3] as $x) {
            expect($tree->negate()->evaluate(['x' => $x]))->toBe(-$tree->evaluate(['x' => $x]));
        }
    }
})->with([
    'a negative power is not a negative number' => [fn () => Expr::pow(Expr::n(-10), Expr::n(2)), '(-10)^2'],
    'nor with an odd exponent' => [fn () => Expr::pow(Expr::n(-10), Expr::n(-1)), '(-10)^(-1)'],
    'a negative product is' => [fn () => Expr::mul(Expr::n(-3), Expr::v('x')), '-3x'],
    'a sum led by a negative term is' => [fn () => Expr::add(Expr::n(-6), Expr::v('x')), '-6 + x'],
    'negating a sum flips the operator too' => [fn () => Expr::sub(Expr::n(-6), Expr::n(3)), '-6 - 3'],
]);

it('keeps that invariant across every node of a generated expression', function (): void {
    foreach (equationSweep('equations.expression', ['functions' => 'full', 'variables' => 1, 'depth' => 5, 'count' => 6], seeds: 50) as $problem) {
        $walk = function (Node $node) use (&$walk, $problem): void {
            if ($node->isNegative()) {
                $value = $node->evaluate($problem->check['bindings']);
                $negated = $node->negate()->evaluate($problem->check['bindings']);

                if (is_finite($value) && is_finite($negated)) {
                    expect($negated)->toBe(-$value, "printing lies about the sign of: {$node->toPlain()}");
                }
            }

            foreach ($node->children() as $child) {
                $walk($child);
            }
        };

        $walk($problem->check['expression']);
    }
});

it('differentiates in agreement with a finite difference', function (Node $f): void {
    $derivative = $f->derive('x')->simplify();

    foreach ([0.7, 1.3, 2.1] as $x) {
        $numeric = ($f->evaluate(['x' => $x + 1e-6]) - $f->evaluate(['x' => $x - 1e-6])) / 2e-6;

        expect($derivative->evaluate(['x' => $x]))->toBeBetween($numeric - 1e-4, $numeric + 1e-4);
    }
})->with([
    'polynomial' => [fn () => Expr::polynomial([2, -3, 0, 5])],
    'product' => [fn () => Expr::mul(Expr::v('x'), Expr::sin(Expr::v('x')))],
    'quotient' => [fn () => Expr::div(Expr::add(Expr::v('x'), Expr::n(1)), Expr::add(Expr::v('x'), Expr::n(3)))],
    'chain' => [fn () => Expr::sin(Expr::add(Expr::mul(Expr::n(3), Expr::v('x')), Expr::n(1)))],
    'logarithm' => [fn () => Expr::ln(Expr::add(Expr::mul(Expr::n(2), Expr::v('x')), Expr::n(1)))],
    'exponential' => [fn () => Expr::exp(Expr::mul(Expr::n(-2), Expr::v('x')))],
    'root' => [fn () => Expr::sqrt(Expr::add(Expr::pow(Expr::v('x'), Expr::n(2)), Expr::n(1)))],
    'tangent' => [fn () => Expr::tan(Expr::v('x'))],
    'variable exponent' => [fn () => Expr::pow(Expr::v('x'), Expr::v('x'))],
]);

it('prints small rationals as fractions rather than as decimals', function (): void {
    expect(Fmt::plain(0.75))->toBe('3/4')
        ->and(Fmt::latex(-2 / 3))->toBe('-\frac{2}{3}')
        ->and(Fmt::plain(12.0))->toBe('12')
        ->and(Fmt::simplifySurd(50))->toBe([5, 2]);
});

/* ── The backward constructions ────────────────────────────────────────── */

it('builds linear equations that the stated answer actually solves', function (string $tier, bool $integers): void {
    // The single most valuable test in the module: it does not check that the
    // generator ran, it checks that the mathematics it printed is true.
    $problems = equationSweep('equations.linear', [
        'tier' => $tier, 'count' => 6, 'integer_solutions' => $integers,
    ], seeds: 40);

    expect($problems)->toHaveCount(240);

    foreach ($problems as $problem) {
        $x = $problem->check['solution'];

        expect($problem->check['lhs']->evaluate(['x' => $x]))
            ->toBeBetween($problem->check['rhs']->evaluate(['x' => $x]) - 1e-9, $problem->check['rhs']->evaluate(['x' => $x]) + 1e-9)
            ->and($problem->answer)->toBe('x = '.Fmt::plain($x));
    }
})->with([
    ['one_step', true], ['two_step', true], ['two_sided', true], ['brackets', true], ['mixed', true],
    ['one_step', false], ['two_step', false], ['two_sided', false], ['brackets', false], ['mixed', false],
]);

it('builds quadratics whose stated roots are roots', function (string $kind): void {
    $problems = equationSweep('equations.quadratic', ['roots' => $kind, 'count' => 6], seeds: 40);

    foreach ($problems as $problem) {
        foreach ($problem->check['roots'] as $root) {
            expect($problem->check['polynomial']->evaluate(['x' => $root]))->toBeBetween(-1e-6, 1e-6);
        }
    }
})->with([['integer'], ['rational'], ['surd'], ['mixed']]);

it('keeps integer and rational quadratics factorable over the rationals', function (string $kind): void {
    // A discriminant that is a perfect square is exactly the condition for the
    // roots to be rational, which is the promise these two tiers make.
    foreach (equationSweep('equations.quadratic', ['roots' => $kind, 'count' => 5], seeds: 25) as $problem) {
        [$r1, $r2] = $problem->check['roots'];

        expect(Fmt::asFraction($r1) !== null || Fmt::isInteger($r1))->toBeTrue()
            ->and(Fmt::asFraction($r2) !== null || Fmt::isInteger($r2))->toBeTrue();
    }
})->with([['integer'], ['rational']]);

it('gives a surd quadratic a genuinely irrational pair of roots', function (): void {
    foreach (equationSweep('equations.quadratic', ['roots' => 'surd', 'count' => 5], seeds: 25) as $problem) {
        expect($problem->extra['discriminant'])->toBeGreaterThan(0)
            ->and(Fmt::isPerfectSquare($problem->extra['discriminant']))->toBeFalse()
            ->and($problem->answer)->toContain('±');
    }
});

it('builds systems that the stated solution satisfies exactly', function (string $size): void {
    foreach (equationSweep('equations.system', ['size' => $size, 'count' => 4], seeds: 40) as $problem) {
        $product = Matrix::apply($problem->check['matrix'], $problem->check['solution']);

        // Integers throughout, so this is an exact comparison rather than a
        // tolerance — which is the whole reason the matrix is unimodular.
        expect($product)->toBe($problem->check['rhs'])
            ->and(abs(Matrix::determinant($problem->check['matrix'])))->toBe(1);
    }
})->with([['2'], ['3']]);

it('never prints a system with a row that gives itself away', function (): void {
    foreach (equationSweep('equations.system', ['size' => '3', 'count' => 3], seeds: 30) as $problem) {
        foreach ($problem->check['matrix'] as $row) {
            expect(count(array_filter($row)))->toBeGreaterThanOrEqual(2);
        }
    }
});

/* ── Calculus ──────────────────────────────────────────────────────────── */

it('states a derivative that a finite difference agrees with', function (string $rule): void {
    foreach (equationSweep('equations.calculus', ['mode' => 'derivative', 'rule' => $rule, 'count' => 6], seeds: 30) as $problem) {
        $f = $problem->check['function'];
        $x = $problem->check['point'];
        $h = 1e-6;

        $numeric = ($f->evaluate(['x' => $x + $h]) - $f->evaluate(['x' => $x - $h])) / (2 * $h);
        $symbolic = $problem->check['answer']->evaluate(['x' => $x]);

        expect(is_finite($symbolic))->toBeTrue()
            ->and(abs($symbolic - $numeric))->toBeLessThan(1e-3 * max(1.0, abs($numeric)));
    }
})->with([['power'], ['product'], ['quotient'], ['chain'], ['trig'], ['exp_log'], ['mixed']]);

it('states an integral whose answer differentiates back to the question', function (string $rule): void {
    // The proof that the inversion works. Integration is not closed over this
    // function set, so the only way to be sure an integral is solvable is to
    // have built it by differentiating — and this is that claim, checked.
    foreach (equationSweep('equations.calculus', ['mode' => 'integral', 'rule' => $rule, 'count' => 6], seeds: 30) as $problem) {
        $integrand = $problem->check['function'];
        $recovered = $problem->check['answer']->derive('x')->simplify();
        $x = $problem->check['point'];

        expect($recovered->evaluate(['x' => $x]))
            ->toBeBetween(
                $integrand->evaluate(['x' => $x]) - 1e-6 * max(1.0, abs($integrand->evaluate(['x' => $x]))),
                $integrand->evaluate(['x' => $x]) + 1e-6 * max(1.0, abs($integrand->evaluate(['x' => $x]))),
            )
            ->and($problem->answer)->toEndWith('+ C');
    }
})->with([['power'], ['product'], ['quotient'], ['chain'], ['trig'], ['exp_log'], ['mixed']]);

/* ── Matrices ──────────────────────────────────────────────────────────── */

it('gives a matrix the determinant it claims', function (string $kind): void {
    foreach (equationSweep('equations.matrix', ['kind' => $kind, 'rows' => 3, 'count' => 2], seeds: 40) as $problem) {
        expect(Matrix::determinant($problem->extra['matrix']))->toBe($problem->extra['determinant']);
    }
})->with([['any'], ['invertible'], ['determinant'], ['symmetric'], ['spd']]);

it('hits the requested determinant exactly', function (int $target): void {
    foreach (equationSweep('equations.matrix', ['kind' => 'determinant', 'determinant' => $target, 'rows' => 3, 'count' => 2], seeds: 20) as $problem) {
        expect($problem->extra['determinant'])->toBe($target);
    }
})->with([[1], [-1], [6], [-12], [7]]);

it('makes a positive-definite matrix that really is positive-definite', function (int $rows): void {
    foreach (equationSweep('equations.matrix', ['kind' => 'spd', 'rows' => $rows, 'count' => 2], seeds: 30) as $problem) {
        // Sylvester's criterion on exact integer minors — not an eigenvalue
        // estimate, so there is no tolerance to argue about.
        expect(Matrix::isPositiveDefinite($problem->extra['matrix']))->toBeTrue()
            ->and($problem->extra['determinant'])->toBeGreaterThan(0);
    }
})->with([[2], [3], [4]]);

it('keeps a symmetric matrix symmetric and an invertible one invertible', function (): void {
    foreach (equationSweep('equations.matrix', ['kind' => 'symmetric', 'rows' => 4, 'count' => 2], seeds: 20) as $problem) {
        expect(Matrix::isSymmetric($problem->extra['matrix']))->toBeTrue();
    }

    foreach (equationSweep('equations.matrix', ['kind' => 'invertible', 'rows' => 3, 'count' => 2], seeds: 20) as $problem) {
        expect(abs($problem->extra['determinant']))->toBe(1);
    }
});

it('squares a matrix off when the kind demands it', function (): void {
    // 'symmetric' with 3 rows and 5 columns is not a request that can be
    // honoured; clamping is kinder than a 422 mid-slider-drag, but it has to
    // actually happen.
    foreach (equationProblems('equations.matrix', ['kind' => 'spd', 'rows' => 3, 'cols' => 5, 'count' => 3]) as $problem) {
        expect($problem->extra['matrix'])->toHaveCount(3)
            ->and($problem->extra['matrix'][0])->toHaveCount(3);
    }
});

/* ── True or false ─────────────────────────────────────────────────────── */

it('ships only true identities in the bank', function (): void {
    foreach (Identities::all() as $identity) {
        expect(Identities::holds($identity['lhs'], $identity['rhs'], $identity['domain']))
            ->toBeTrue("[{$identity['key']}] is in the identity bank and is not an identity.");
    }
});

it('is true exactly when it says it is', function (string $topic): void {
    // Checked at points of the test's own choosing rather than the generator's,
    // so this is an independent verdict and not the generator agreeing with
    // itself. A corruption that happens to land on another true identity must
    // come out labelled true — that is the failure this guards.
    $points = [
        Identities::TRIG => [0.41, 1.07, 1.93, 2.5, -0.31, 0.95],
        Identities::LOG => [0.6, 1.8, 3.1, 4.7, 0.25, 2.6],
    ];

    foreach (equationSweep('equations.identity', ['topic' => $topic, 'count' => 6], seeds: 40) as $problem) {
        $values = $points[$problem->check['domain']];
        $agreements = 0;
        $disagreements = 0;

        foreach ($values as $index => $value) {
            $bindings = ['x' => $value, 'a' => $value, 'b' => $values[($index + 3) % count($values)]];

            $left = $problem->check['lhs']->evaluate($bindings);
            $right = $problem->check['rhs']->evaluate($bindings);

            if (! is_finite($left) || ! is_finite($right) || abs($left) > 1e6 || abs($right) > 1e6) {
                continue;
            }

            abs($left - $right) <= 1e-7 * max(1.0, abs($left), abs($right))
                ? $agreements++
                : $disagreements++;
        }

        if ($problem->check['holds']) {
            expect($disagreements)->toBe(0, "claimed true but fails at a probe point: {$problem->prompt}")
                ->and($problem->answer)->toStartWith('True');
        } else {
            expect($disagreements)->toBeGreaterThan(0, "claimed false but holds everywhere tested: {$problem->prompt}")
                ->and($problem->answer)->toStartWith('False');
        }

        expect($agreements + $disagreements)->toBeGreaterThanOrEqual(3);
    }
})->with([['trig'], ['log'], ['mixed']]);

it('corrupts at most one thing, so the statement stays recognisable', function (): void {
    // Compared as trees rather than as strings. A single-token edit leaves the
    // shape untouched and exactly one label different, which a printed
    // comparison cannot see: dropping a coefficient of 2 to 1 changes `2sin a`
    // into `sin a` on the page while changing one leaf in the tree.
    $bank = [];

    foreach (Identities::all() as $identity) {
        $bank[$identity['key']] = $identity;
    }

    $differences = function (Node $a, Node $b) use (&$differences): int {
        $children = $a->children();
        $others = $b->children();

        if (count($children) !== count($others)) {
            // A shape change is not a single-token edit at all; reported as
            // something large so the assertion below fails loudly.
            return 99;
        }

        $count = 0;

        foreach ($children as $index => $child) {
            $count += $differences($child, $others[$index]);
        }

        // Compare this node alone, with its children stripped out of the label.
        return $count + (explode("\n", $a->toTree())[0] === explode("\n", $b->toTree())[0] ? 0 : 1);
    };

    foreach (equationSweep('equations.identity', ['count' => 6], seeds: 30) as $problem) {
        $original = $bank[$problem->extra['identity']];
        $edits = $differences($original['lhs'], $problem->check['lhs'])
            + $differences($original['rhs'], $problem->check['rhs']);

        expect($edits)->toBeLessThanOrEqual(1, "more than one edit in: {$problem->prompt}")
            ->and($edits)->toBe(isset($problem->extra['corruption']) ? 1 : 0);
    }
});

/* ── Forward generation ────────────────────────────────────────────────── */

it('never produces an expression that evaluates to NaN or infinity', function (string $functions, int $variables): void {
    $problems = equationSweep('equations.expression', [
        'functions' => $functions, 'variables' => $variables, 'depth' => 5, 'constants' => true, 'count' => 8,
    ], seeds: 60);

    foreach ($problems as $problem) {
        $value = $problem->check['expression']->evaluate($problem->check['bindings']);

        expect(is_finite($value))->toBeTrue("not finite: {$problem->prompt}")
            ->and(abs($value))->toBeLessThan(1e12)
            ->and($problem->prompt)->not->toContain('NAN', 'INF', 'undefined');
    }
})->with([
    ['none', 0], ['none', 1], ['roots', 1], ['trig', 1], ['full', 1], ['full', 3],
]);

it('grows an expression rather than handing back a leaf', function (): void {
    foreach (equationSweep('equations.expression', ['depth' => 3, 'count' => 8], seeds: 30) as $problem) {
        expect($problem->check['expression']->nodeCount())->toBeGreaterThanOrEqual(3)
            ->and($problem->check['expression']->variables())->not->toBeEmpty();
    }
});

it('keeps the terminal probability rising with depth, so recursion ends', function (): void {
    $grammar = new Grammar(
        equationSeed(1)->rng('equations.expression', 1),
        maxDepth: 4,
    );

    $previous = 0.0;

    foreach (range(0, 4) as $depth) {
        $p = $grammar->terminalProbability($depth);

        expect($p)->toBeGreaterThan($previous)->toBeLessThan(1.0);
        $previous = $p;
    }

    // P(leaf | 0) = 1 − (1 − 0.25)^1 = 0.25, exactly as the grammar documents.
    expect($grammar->terminalProbability(0))->toBeBetween(0.2499, 0.2501);
});

/* ── Arithmetic and sequences ──────────────────────────────────────────── */

it('states an arithmetic answer equal to the expression it printed', function (string $operations): void {
    foreach (equationSweep('equations.arithmetic', ['operations' => $operations, 'digits' => 3, 'count' => 10], seeds: 25) as $problem) {
        $value = $problem->check['expression']->evaluate();

        expect((float) $problem->answer)->toBe($value)
            // Division is built backwards from the quotient precisely so this
            // holds: a drill that needs a remainder is not a drill.
            ->and(Fmt::isInteger($value))->toBeTrue("not a whole number: {$problem->prompt}");
    }
})->with([['add'], ['sub'], ['mul'], ['div'], ['all']]);

it('continues a sequence the way it says it does', function (string $kind): void {
    foreach (equationSweep('equations.sequence', ['kind' => $kind, 'terms' => 5, 'count' => 6], seeds: 30) as $problem) {
        $terms = [...$problem->check['terms'], $problem->check['next']];
        $n = count($terms);

        $continues = match ($problem->check['kind']) {
            'arithmetic' => $terms[$n - 1] - $terms[$n - 2] === $terms[1] - $terms[0],
            'geometric' => $terms[$n - 1] * $terms[0] === $terms[$n - 2] * $terms[1],
            'fibonacci' => $terms[$n - 1] === $terms[$n - 2] + $terms[$n - 3],
            // A quadratic is exactly a sequence whose second differences are
            // constant, which is a cheaper statement to check than refitting it.
            default => $terms[$n - 1] - 2 * $terms[$n - 2] + $terms[$n - 3] === $terms[2] - 2 * $terms[1] + $terms[0],
        };

        expect($continues)->toBeTrue("{$problem->prompt} → {$problem->answer}");
        expect($problem->check['terms'])->toHaveCount(5);
    }
})->with([['arithmetic'], ['geometric'], ['quadratic'], ['fibonacci'], ['mixed']]);

/* ── The bar the whole module is held to ───────────────────────────────── */

it('never prints a term that says nothing', function (string $key): void {
    // `((3+0)·1)/((2)^1)` is technically valid and reads as noise, and output
    // that reads as noise is a failure of this module whatever the other tests
    // say. These are the patterns that give generated maths away — every one of
    // them has actually appeared in this module's output at some point.
    $noise = [
        '/[+\-] 0(?![0-9.])/' => 'adds or subtracts zero',
        '/(?<![0-9.\/])1\s*\*/' => 'multiplies by one',
        '/\*\s*1(?![0-9.\/])/' => 'multiplies by one',
        '/\^1(?![0-9])/' => 'raises to the first power',
        '/\^0(?![0-9])/' => 'raises to the zeroth power',
        '/\(\s*\)/' => 'has empty brackets',
        '/[+\-]\s+[+\-]/' => 'stacks two signs',
        '/(?<![0-9a-zA-Z.])0\s*[*\/]/' => 'multiplies or divides by zero',
        '/\bNAN\b|\bINF\b/i' => 'is not a number',
    ];

    foreach (equationVariants()[$key] as $index => $input) {
        for ($seed = 0; $seed < 20; $seed++) {
            foreach (equationProblems($key, [...$input, 'count' => 8], $seed * 7 + $index) as $problem) {
                foreach ($noise as $pattern => $complaint) {
                    expect(preg_match($pattern, $problem->prompt))
                        ->toBe(0, "[{$key}] {$complaint}: {$problem->prompt}");
                    expect(preg_match($pattern, $problem->answer))
                        ->toBe(0, "[{$key}] answer {$complaint}: {$problem->answer}");
                }
            }
        }
    }
})->with([
    ['equations.arithmetic'], ['equations.expression'], ['equations.linear'],
    ['equations.quadratic'], ['equations.system'], ['equations.calculus'],
    ['equations.identity'], ['equations.sequence'],
]);

it('gives every problem both a plain form and a LaTeX one', function (string $key): void {
    $generator = allGenerators()[$key];
    $params = $generator->schema()->coerce(['count' => 5]);
    $result = $generator->generate(
        equationSeed(7)->rng($key, $generator->version(), $params->fingerprint()),
        $params,
    );

    expect($result->meta['latex'])->toBeArray()->toHaveCount(count($result->value['problems']));

    foreach ($result->value['problems'] as $index => $problem) {
        expect($problem['prompt'])->not->toBeEmpty()
            ->and($problem['prompt_latex'])->not->toBeEmpty()
            ->and($problem['answer'])->not->toBeEmpty()
            ->and($problem['answer_latex'])->not->toBeEmpty()
            ->and($result->meta['latex'][$index])->toBe([$problem['prompt_latex'], $problem['answer_latex']]);
    }

    // The worksheet and the clipboard read the plain form; the page reads the
    // LaTeX. Neither may be a stand-in for the other.
    expect($result->display)->toContain($result->value['problems'][0]['prompt']);
})->with([
    ['equations.arithmetic'], ['equations.expression'], ['equations.linear'],
    ['equations.quadratic'], ['equations.system'], ['equations.calculus'],
    ['equations.matrix'], ['equations.identity'], ['equations.sequence'],
]);

it('prints the same worksheet from the same seed and a different one from another', function (string $key): void {
    // The worksheet's entire promise, at the level that decides it.
    $first = equationProblems($key, ['count' => 12], seed: 3);
    $again = equationProblems($key, ['count' => 12], seed: 3);
    $other = equationProblems($key, ['count' => 12], seed: 4);

    $prompts = fn (array $problems): array => array_map(fn (Problem $p): string => $p->prompt, $problems);

    expect($prompts($first))->toBe($prompts($again))
        ->and($prompts($first))->not->toBe($prompts($other))
        ->and($first)->toHaveCount(12);
})->with([
    ['equations.arithmetic'], ['equations.linear'], ['equations.quadratic'],
    ['equations.calculus'], ['equations.identity'], ['equations.sequence'],
]);

/* ── The half PHP cannot see ───────────────────────────────────────────── */

it('emits LaTeX that KaTeX will actually parse', function (): void {
    // The browser half of this module, checked the way RendererCoverageTest
    // checks the canvas half. A malformed expression renders as red error text
    // on the page while every assertion above stays green — `(e^a)^2` printed as
    // `e^{a}^{2}`, a double superscript, and nothing on the PHP side could have
    // known. So the real parser is asked.
    exec('node --version 2>/dev/null', $out, $code);

    if ($code !== 0) {
        test()->markTestSkipped('node is not available; the LaTeX check needs it.');
    }

    $strings = [];

    foreach (equationVariants() as $key => $inputs) {
        $generator = allGenerators()[$key];

        foreach ($inputs as $index => $input) {
            for ($seed = 0; $seed < 8; $seed++) {
                $params = $generator->schema()->coerce([...$input, 'count' => 8]);
                $result = $generator->generate(
                    equationSeed($seed * 31 + $index)->rng($key, $generator->version(), $params->fingerprint()),
                    $params,
                );

                foreach ($result->meta['latex'] as [$prompt, $answer]) {
                    $strings[] = $prompt;
                    $strings[] = $answer;
                }
            }
        }
    }

    $strings = array_values(array_unique($strings));

    expect($strings)->toHaveCount(count($strings))->and(count($strings))->toBeGreaterThan(500);

    $process = Process::fromShellCommandline(
        'node scripts/check-latex.mjs',
        dirname(__DIR__, 2),
        input: json_encode($strings, JSON_THROW_ON_ERROR),
    );
    $process->setTimeout(120);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('node failed: '.$process->getErrorOutput());
    }

    $failures = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($failures)->toBe([], 'KaTeX refused: '.json_encode(array_slice($failures, 0, 5)));
});
