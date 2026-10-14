<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\UnsolvableException;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Numbers\Functions;
use Vortech\Factor\Numbers\Number;

/**
 * Solves polynomial equations in one variable.
 *
 * Degrees 1 and 2 are solved in closed form, exactly: rational roots stay rational and the others keep an exact
 * expression such as (-1 + sqrt(5))/2. Higher degrees are solved by the Durand-Kerner iteration to the context's
 * precision, and every real root that turns out to be rational is recognised, verified exactly and divided out,
 * so a cubic with one rational root still gives the rest in closed form.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class EquationSolver
{
    private const MAX_RATIONAL_DENOMINATOR = '1000000000000';

    /**
     * @throws UnsolvableException when the expression is not a polynomial in the variable, or the iteration fails
     */
    public static function solve(Expression $expression, string $variable, Context $context): Solution
    {
        $others = array_values(array_diff($expression->variables(), [$variable]));

        if ($others !== []) {
            throw new UnsolvableException('The equation also contains '.implode(', ', $others).'; give a value for each, or solve a system.');
        }

        $polynomial = Polynomial::fromExpression($expression, $variable)
            ?? throw new UnsolvableException('Not a polynomial in '.$variable.'. Use root() with a starting point or an interval.');

        if ($polynomial->isZero()) {
            return new Solution([], all: true);
        }

        if ($polynomial->degree() === 0) {
            return new Solution([]);
        }

        $roots = self::roots($polynomial->squarefree(), $context);

        usort($roots, static fn (Root $a, Root $b): int => [! $a->isReal(), $a->real->toFloat(), $a->imaginary->toFloat()]
            <=> [! $b->isReal(), $b->real->toFloat(), $b->imaginary->toFloat()]);

        return new Solution($roots);
    }

    /**
     * @return list<Root>
     */
    private static function roots(Polynomial $polynomial, Context $context): array
    {
        return match ($polynomial->degree()) {
            0 => [],
            1 => [self::rational($polynomial->coefficient(0)->negate()->div($polynomial->coefficient(1)))],
            2 => self::quadratic($polynomial, $context),
            default => self::higher($polynomial, $context),
        };
    }

    private static function rational(Number $value): Root
    {
        return new Root($value, Number::zero(), Expression::number($value), true);
    }

    /**
     * @return list<Root>
     */
    private static function quadratic(Polynomial $polynomial, Context $context): array
    {
        $places = $context->precision + 10;
        [$c, $b, $a] = [$polynomial->coefficient(0), $polynomial->coefficient(1), $polynomial->coefficient(2)];
        $discriminant = $b->mul($b)->sub($a->mul($c)->mul(4));
        $twoA = $a->mul(2);
        $center = $b->negate()->div($twoA);

        if ($discriminant->isNegative()) {
            $magnitude = $discriminant->negate();
            $exactRoot = $magnitude->exactSqrt();
            $imaginary = ($exactRoot ?? Functions::sqrt($magnitude, $places))->div($twoA->abs());

            return [
                new Root($center, $imaginary, null, $exactRoot !== null),
                new Root($center, $imaginary->negate(), null, $exactRoot !== null),
            ];
        }

        $exactRoot = $discriminant->exactSqrt();

        if ($exactRoot !== null) {
            return [
                self::rational($b->negate()->sub($exactRoot)->div($twoA)),
                self::rational($b->negate()->add($exactRoot)->div($twoA)),
            ];
        }

        $root = Functions::sqrt($discriminant, $places)->div($twoA)->abs();
        [$outside, $radicand] = self::radical($discriminant);
        $closedForm = Expression::number($outside->div($twoA->abs()))->times(Expression::number($radicand)->sqrt());

        return [
            new Root($center->sub($root), Number::zero(), Expression::number($center)->minus($closedForm), true),
            new Root($center->add($root), Number::zero(), Expression::number($center)->plus($closedForm), true),
        ];
    }

    /**
     * Pulls the square factors out of a root: sqrt(8) = 2 * sqrt(2) gives [2, 2], sqrt(3/4) gives [1/2, 3].
     * Gives up on very large numbers and returns [1, value].
     *
     * @return array{Number, Number} the factor outside and the number left under the root
     */
    private static function radical(Number $value): array
    {
        // sqrt(n/d) = sqrt(n*d)/d
        $whole = Number::of($value->numerator)->mul($value->denominator);
        $outside = Number::one();
        $remaining = $whole;

        for ($factor = 2; $factor <= 100_000; $factor++) {
            $square = Number::of($factor)->mul($factor);

            if ($square->greaterThan($remaining)) {
                break;
            }

            while ($remaining->div($square)->isInteger()) {
                $remaining = $remaining->div($square);
                $outside = $outside->mul($factor);
            }
        }

        if ($remaining->greaterThan(Number::of(10)->pow(40))) {
            return [Number::one(), $value];
        }

        return [$outside->div($value->denominator), $remaining];
    }

    /**
     * @return list<Root>
     */
    private static function higher(Polynomial $polynomial, Context $context): array
    {
        $places = $context->precision + 10;
        $numeric = self::durandKerner($polynomial, $places, $context);

        foreach ($numeric as $candidate) {
            if (! $candidate->imaginary->abs()->lessThan(Number::of(10)->pow(-($places - 4)))) {
                continue;
            }

            $rational = self::rationalize($candidate->real, $polynomial);

            if ($rational !== null) {
                [$quotient] = $polynomial->divide(Polynomial::of([$rational->negate(), 1]));

                return [self::rational($rational), ...self::roots($quotient, $context)];
            }
        }

        return array_map(
            static fn (Complex $z): Root => new Root(
                $z->real,
                $z->imaginary->abs()->lessThan(Number::of(10)->pow(-($places - 4))) ? Number::zero() : $z->imaginary,
                null,
                false,
            ),
            $numeric,
        );
    }

    /**
     * @return list<Complex>
     *
     * @throws UnsolvableException
     */
    private static function durandKerner(Polynomial $polynomial, int $places, Context $context): array
    {
        $n = $polynomial->degree();
        $monic = $polynomial->monic();
        $tolerance = Number::of(10)->pow(-$places);

        $radius = Number::one();

        foreach ($monic->coefficients as $power => $c) {
            if ($power < $n) {
                $radius = $radius->max($c->abs()->add(1));
            }
        }

        $seed = Complex::of('0.4', '0.9');
        $z = [];
        $power = Complex::of(1, 0);

        for ($k = 0; $k < $n; $k++) {
            $z[] = new Complex($power->real->mul($radius), $power->imaginary->mul($radius));
            $power = $power->mul($seed)->round($places);
        }

        $evaluate = static function (Complex $x) use ($monic, $places): Complex {
            $result = Complex::of(0, 0);

            foreach (array_reverse($monic->coefficients) as $c) {
                $result = $result->mul($x)->add(new Complex($c, Number::zero()))->round($places + 5);
            }

            return $result;
        };

        $limit = max($context->maxIterations * 5, 500);

        for ($iteration = 0; $iteration < $limit; $iteration++) {
            $largest = Number::zero();

            for ($i = 0; $i < $n; $i++) {
                $denominator = Complex::of(1, 0);

                for ($j = 0; $j < $n; $j++) {
                    if ($j !== $i) {
                        $denominator = $denominator->mul($z[$i]->sub($z[$j]))->round($places + 5);
                    }
                }

                if ($denominator->isZero()) {
                    $z[$i] = $z[$i]->add(Complex::of('0.000001', '0.000001'));

                    continue;
                }

                $step = $evaluate($z[$i])->div($denominator)->round($places + 5);
                $z[$i] = $z[$i]->sub($step)->round($places + 5);
                $largest = $largest->max($step->normSquared());
            }

            if ($largest->lessThanOrEqual($tolerance->mul($tolerance))) {
                return array_values(array_map(static fn (Complex $x): Complex => $x->round($places), $z));
            }
        }

        throw new UnsolvableException('The root iteration did not converge.');
    }

    /**
     * The rational number the value is a root-approximation of, if the polynomial vanishes exactly there. Tries the
     * continued fraction convergents of the value.
     */
    private static function rationalize(Number $value, Polynomial $polynomial): ?Number
    {
        [$previousH, $h] = ['0', '1'];
        [$previousK, $k] = ['1', '0'];
        $remaining = $value;
        $epsilon = Number::of(10)->pow(-20);

        for ($i = 0; $i < 80; $i++) {
            $integer = $remaining->floor();
            [$previousH, $h] = [$h, bcadd(bcmul($integer->numerator, $h, 0), $previousH, 0)];
            [$previousK, $k] = [$k, bcadd(bcmul($integer->numerator, $k, 0), $previousK, 0)];

            if (bccomp($k, self::MAX_RATIONAL_DENOMINATOR, 0) > 0) {
                return null;
            }

            $candidate = Number::fraction($h, $k);

            if ($polynomial->evaluate($candidate)->isZero()) {
                return $candidate;
            }

            $fraction = $remaining->sub($integer);

            if ($fraction->lessThan($epsilon)) {
                return null;
            }

            $remaining = $fraction->reciprocal();
        }

        return null;
    }
}
