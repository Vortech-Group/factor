<?php

declare(strict_types=1);

namespace Vortech\Factor\Calculus;

use Closure;
use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Functions;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Results\IntegralResult;

/**
 * Numeric definite integration at the context's precision. The nodes, weights and sums are exact rationals
 * rounded only to a working precision a few digits beyond the one asked for, and every result carries an
 * error estimate and a `converged` flag, so a hard integrand is reported instead of silently approximated.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class Integrator
{
    private const NODES = 20;

    private const MAX_DEPTH = 48;

    /** The most times an interval may be halved in total, so a hopeless integrand ends in a bounded time. */
    private const MAX_SPLITS = 400;

    private const MAX_ROMBERG_LEVELS = 14;

    /** @var array<string, array{list<Number>, list<Number>}> */
    private static array $rules = [];

    /**
     * @param  Closure(Number): Number  $f
     *
     * @throws DomainException when the integrand is not defined where it is evaluated
     */
    public static function integrate(Closure $f, Number $from, Number $to, IntegrationMethod $method, Context $context): IntegralResult
    {
        if ($from->equals($to)) {
            return new IntegralResult(Number::zero(), Number::zero(), 0, true, $method);
        }

        $sign = 1;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
            $sign = -1;
        }

        $result = match ($method) {
            IntegrationMethod::GaussLegendre => self::gauss($f, $from, $to, $context),
            IntegrationMethod::Romberg => self::romberg($f, $from, $to, $context),
        };

        [$value, $error, $evaluations, $converged] = $result;

        return new IntegralResult($sign === 1 ? $value : $value->negate(), $error, $evaluations, $converged, $method);
    }

    /**
     * @param  Closure(Number): Number  $f
     * @return array{Number, Number, int, bool}
     */
    private static function gauss(Closure $f, Number $a, Number $b, Context $context): array
    {
        $working = $context->precision + 10;
        $tolerance = $context->tolerance();
        $length = $b->sub($a);
        $evaluations = 0;

        $rule = static function (Number $left, Number $right) use ($f, $working, &$evaluations): Number {
            [$nodes, $weights] = self::rule($working);
            $half = $right->sub($left)->div(2);
            $middle = $right->add($left)->div(2);
            $sum = Number::zero();

            foreach ($nodes as $index => $node) {
                $sum = $sum->add($weights[$index]->mul($f($middle->add($half->mul($node)))));
                $evaluations++;
            }

            return $sum->mul($half)->round($working);
        };

        $total = Number::zero();
        $error = Number::zero();
        $stack = [[$a, $b, $rule($a, $b), 0]];
        $splits = 0;

        while ($stack !== []) {
            [$left, $right, $whole, $depth] = array_pop($stack);
            $middle = $left->add($right)->div(2);
            $first = $rule($left, $middle);
            $second = $rule($middle, $right);
            $difference = $first->add($second)->sub($whole)->abs();

            if ($difference->lessThanOrEqual($tolerance->mul($right->sub($left))->div($length)->div(10))) {
                $total = $total->add($first)->add($second);
                $error = $error->add($difference);

                continue;
            }

            if ($depth >= self::MAX_DEPTH || $splits >= self::MAX_SPLITS) {
                $total = $total->add($first)->add($second);
                $error = $error->add($difference);

                continue;
            }

            $splits++;
            $stack[] = [$left, $middle, $first, $depth + 1];
            $stack[] = [$middle, $right, $second, $depth + 1];
        }

        return [$total->round($working), $error, $evaluations, $error->lessThanOrEqual($tolerance)];
    }

    /**
     * @param  Closure(Number): Number  $f
     * @return array{Number, Number, int, bool}
     */
    private static function romberg(Closure $f, Number $a, Number $b, Context $context): array
    {
        $working = $context->precision + 10;
        $tolerance = $context->tolerance();
        $length = $b->sub($a);
        $evaluations = 2;

        $table = [[$f($a)->add($f($b))->mul($length)->div(2)->round($working)]];

        for ($level = 1; $level <= self::MAX_ROMBERG_LEVELS; $level++) {
            $steps = 2 ** ($level - 1);
            $step = $length->div(2 * $steps);
            $sum = Number::zero();

            for ($i = 0; $i < $steps; $i++) {
                $sum = $sum->add($f($a->add($step->mul(2 * $i + 1))->round($working)));
                $evaluations++;
            }

            $row = [$table[$level - 1][0]->div(2)->add($sum->mul($step))->round($working)];

            for ($k = 1; $k <= $level; $k++) {
                $factor = Number::of(4)->pow($k);
                $row[$k] = $row[$k - 1]->add($row[$k - 1]->sub($table[$level - 1][$k - 1])->div($factor->sub(1)))->round($working);
            }

            $table[$level] = $row;
            $error = $row[$level]->sub($table[$level - 1][$level - 1])->abs();

            if ($level >= 3 && $error->lessThanOrEqual($tolerance)) {
                return [$row[$level], $error, $evaluations, true];
            }
        }

        $last = self::MAX_ROMBERG_LEVELS;

        return [$table[$last][$last], $table[$last][$last]->sub($table[$last - 1][$last - 1])->abs(), $evaluations, false];
    }

    /**
     * The Gauss-Legendre nodes and weights on [-1, 1], from Newton's method on the Legendre polynomial.
     *
     * @return array{list<Number>, list<Number>}
     */
    private static function rule(int $working): array
    {
        $key = self::NODES.':'.$working;

        if (isset(self::$rules[$key])) {
            return self::$rules[$key];
        }

        $n = self::NODES;
        $nodes = [];
        $weights = [];
        $epsilon = Number::of(10)->pow(-$working);
        $pi = Functions::pi(30);

        for ($i = 1; $i <= intdiv($n, 2); $i++) {
            $x = Functions::cos($pi->mul(Number::of($i)->sub('0.25'))->div(Number::of($n)->add('0.5')), 30);

            for ($iteration = 0; $iteration < 100; $iteration++) {
                [$p, $previous] = self::legendre($x, $n, $working);
                $derivative = $x->mul($p)->sub($previous)->mul($n)->div($x->mul($x)->sub(1));
                $step = $p->div($derivative);
                $x = $x->sub($step)->round($working + 5);

                if ($step->abs()->lessThan($epsilon)) {
                    break;
                }
            }

            [$p, $previous] = self::legendre($x, $n, $working);
            $derivative = $x->mul($p)->sub($previous)->mul($n)->div($x->mul($x)->sub(1));
            $weight = Number::of(2)->div(Number::one()->sub($x->mul($x))->mul($derivative->mul($derivative)))->round($working);

            $nodes[] = $x->negate();
            $weights[] = $weight;
            $nodes[] = $x;
            $weights[] = $weight;
        }

        return self::$rules[$key] = [$nodes, $weights];
    }

    /**
     * P_n(x) and P_(n-1)(x).
     *
     * @return array{Number, Number}
     */
    private static function legendre(Number $x, int $n, int $working): array
    {
        $previous = Number::one();
        $current = $x;

        for ($k = 2; $k <= $n; $k++) {
            $next = $x->mul($current)->mul(2 * $k - 1)->sub($previous->mul($k - 1))->div($k)->round($working + 5);
            $previous = $current;
            $current = $next;
        }

        return [$current, $previous];
    }
}
