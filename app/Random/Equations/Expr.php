<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * Constructors for expression trees.
 *
 * Every generator in the module builds its problems through these rather than by
 * concatenating strings, which is the reason a problem can be differentiated,
 * evaluated at a probe point and printed in two notations without any of those
 * three paths disagreeing about what it says.
 */
final class Expr
{
    public static function n(float|int $value): Num
    {
        return new Num((float) $value);
    }

    public static function pi(): Num
    {
        return new Num(M_PI, '\pi', 'pi');
    }

    public static function euler(): Num
    {
        return new Num(M_E, 'e', 'e');
    }

    public static function v(string $name): Variable
    {
        return new Variable($name);
    }

    /**
     * A sum, with any zero terms left out.
     *
     * The guard is here rather than in simplify() because the generators that
     * build equations backwards compute their constants, and a constant that
     * comes out zero would otherwise be printed: `6(x - 2) = -3x + 0` is a real
     * equation with a real answer and it still looks like a bug.
     */
    public static function add(Node ...$terms): Node
    {
        $kept = array_values(array_filter($terms, fn (Node $t): bool => ! self::isZero($t)));

        return $kept === [] ? self::n(0) : self::fold('+', $kept);
    }

    public static function sub(Node $left, Node $right): Node
    {
        if (self::isZero($right)) {
            return $left;
        }

        return self::isZero($left) ? self::neg($right) : new Binary('-', $left, $right);
    }

    private static function isZero(Node $node): bool
    {
        return $node instanceof Num && ! $node->isSymbolic() && $node->value === 0.0;
    }

    public static function mul(Node ...$factors): Node
    {
        return self::fold('*', $factors);
    }

    public static function div(Node $left, Node $right): Node
    {
        return new Binary('/', $left, $right);
    }

    public static function pow(Node $base, Node $exponent): Node
    {
        return new Binary('^', $base, $exponent);
    }

    public static function neg(Node $operand): Node
    {
        return new Unary('neg', $operand);
    }

    public static function func(string $name, Node $argument): Unary
    {
        return new Unary($name, $argument);
    }

    public static function sin(Node $a): Unary
    {
        return new Unary('sin', $a);
    }

    public static function cos(Node $a): Unary
    {
        return new Unary('cos', $a);
    }

    public static function tan(Node $a): Unary
    {
        return new Unary('tan', $a);
    }

    public static function ln(Node $a): Unary
    {
        return new Unary('ln', $a);
    }

    public static function exp(Node $a): Unary
    {
        return new Unary('exp', $a);
    }

    public static function sqrt(Node $a): Unary
    {
        return new Unary('sqrt', $a);
    }

    public static function abs(Node $a): Unary
    {
        return new Unary('abs', $a);
    }

    /**
     * A polynomial from its coefficients, highest power first.
     *
     * Zero coefficients drop out and a coefficient of one loses its `1·`, so
     * `[2, 0, -5, 1]` prints as `2x^3 - 5x + 1` rather than the long form with
     * the dead terms still in it.
     *
     * @param  list<int|float>  $coefficients
     */
    public static function polynomial(array $coefficients, string $variable = 'x'): Node
    {
        $degree = count($coefficients) - 1;
        $terms = [];

        foreach ($coefficients as $index => $coefficient) {
            if ((float) $coefficient === 0.0) {
                continue;
            }

            $power = $degree - $index;
            $x = self::v($variable);

            $term = match (true) {
                $power === 0 => self::n($coefficient),
                $power === 1 => self::scale($coefficient, $x),
                default => self::scale($coefficient, self::pow($x, self::n($power))),
            };

            $terms[] = $term;
        }

        return $terms === [] ? self::n(0) : self::fold('+', $terms);
    }

    /** `1·u` is just u and `-1·u` is just -u; anything else keeps its coefficient. */
    public static function scale(float|int $coefficient, Node $node): Node
    {
        return match ((float) $coefficient) {
            1.0 => $node,
            -1.0 => self::neg($node),
            0.0 => self::n(0),
            default => self::mul(self::n($coefficient), $node),
        };
    }

    /** @param list<Node> $nodes */
    private static function fold(string $operator, array $nodes): Node
    {
        if ($nodes === []) {
            throw new \InvalidArgumentException("Cannot fold an empty list with [{$operator}].");
        }

        $node = array_shift($nodes);

        foreach ($nodes as $next) {
            $node = new Binary($operator, $node, $next);
        }

        return $node;
    }
}
