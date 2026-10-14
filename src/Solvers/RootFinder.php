<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\UnsolvableException;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Results\RootResult;

/**
 * Iterative root finding for equations a closed form cannot solve, such as cos(x) = x.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class RootFinder
{
    /**
     * Newton's method from a starting point, with the exact symbolic derivative.
     *
     * @throws UnsolvableException when the derivative vanishes on the way
     */
    public static function newton(Expression $expression, string $variable, Number $start, Context $context): RootResult
    {
        self::assertSingleVariable($expression, $variable);

        $places = $context->precision + 10;
        $tolerance = $context->tolerance();
        $f = $expression->toClosure([$variable], $context);
        $derivative = $expression->derivative($variable)->toClosure([$variable], $context);
        $x = $start;

        for ($i = 1; $i <= $context->maxIterations; $i++) {
            $slope = $derivative($x);

            if ($slope->isZero()) {
                throw new UnsolvableException('The derivative is zero at '.$x->toDecimal(10).'; try another starting point.');
            }

            $step = $f($x)->div($slope);
            $x = $x->sub($step)->round($places);

            if ($step->abs()->lessThanOrEqual($tolerance)) {
                return new RootResult($x, $f($x)->abs(), $i, true, 'newton');
            }
        }

        return new RootResult($x, $f($x)->abs(), $context->maxIterations, false, 'newton');
    }

    /**
     * A root inside an interval where the function changes sign, by Brent's method: bisection's certainty with the
     * speed of inverse quadratic interpolation. The interval always contains the root.
     *
     * @throws UnsolvableException when the function does not change sign over the interval
     */
    public static function brent(Expression $expression, string $variable, Number $from, Number $to, Context $context): RootResult
    {
        self::assertSingleVariable($expression, $variable);

        $places = $context->precision + 10;
        $tolerance = $context->tolerance();
        $f = $expression->toClosure([$variable], $context);

        [$a, $b] = $from->greaterThan($to) ? [$to, $from] : [$from, $to];
        [$fa, $fb] = [$f($a), $f($b)];

        if ($fa->isZero()) {
            return new RootResult($a, Number::zero(), 0, true, 'brent');
        }

        if ($fb->isZero()) {
            return new RootResult($b, Number::zero(), 0, true, 'brent');
        }

        if ($fa->sign() === $fb->sign()) {
            throw new UnsolvableException('The function has the same sign at both ends of the interval; there may be no root, or an even number of them.');
        }

        if ($fa->abs()->lessThan($fb->abs())) {
            [$a, $b, $fa, $fb] = [$b, $a, $fb, $fa];
        }

        [$c, $fc, $d] = [$a, $fa, $a];
        $bisected = true;
        $limit = $context->maxIterations * 4;

        for ($i = 1; $i <= $limit; $i++) {
            if ($fb->isZero() || $b->sub($a)->abs()->lessThanOrEqual($tolerance)) {
                return new RootResult($b, $fb->abs(), $i - 1, true, 'brent');
            }

            if (! $fa->equals($fc) && ! $fb->equals($fc)) {
                $s = $a->mul($fb)->mul($fc)->div($fa->sub($fb)->mul($fa->sub($fc)))
                    ->add($b->mul($fa)->mul($fc)->div($fb->sub($fa)->mul($fb->sub($fc))))
                    ->add($c->mul($fa)->mul($fb)->div($fc->sub($fa)->mul($fc->sub($fb))));
            } else {
                $s = $b->sub($fb->mul($b->sub($a))->div($fb->sub($fa)));
            }

            $low = $a->mul(3)->add($b)->div(4);
            $outside = $low->lessThan($b) ? ($s->lessThan($low) || $s->greaterThan($b)) : ($s->greaterThan($low) || $s->lessThan($b));

            if ($outside
                || ($bisected && $s->sub($b)->abs()->greaterThanOrEqual($b->sub($c)->abs()->div(2)))
                || (! $bisected && $s->sub($b)->abs()->greaterThanOrEqual($c->sub($d)->abs()->div(2)))
                || ($bisected && $b->sub($c)->abs()->lessThan($tolerance))
                || (! $bisected && $c->sub($d)->abs()->lessThan($tolerance))) {
                $s = $a->add($b)->div(2);
                $bisected = true;
            } else {
                $bisected = false;
            }

            $s = $s->round($places);
            $fs = $f($s);
            [$d, $c, $fc] = [$c, $b, $fb];

            if ($fa->sign() * $fs->sign() < 0) {
                [$b, $fb] = [$s, $fs];
            } else {
                [$a, $fa] = [$s, $fs];
            }

            if ($fa->abs()->lessThan($fb->abs())) {
                [$a, $b, $fa, $fb] = [$b, $a, $fb, $fa];
            }
        }

        return new RootResult($b, $fb->abs(), $limit, false, 'brent');
    }

    /**
     * @throws UnsolvableException
     */
    private static function assertSingleVariable(Expression $expression, string $variable): void
    {
        $others = array_values(array_diff($expression->variables(), [$variable]));

        if ($others !== []) {
            throw new UnsolvableException('The equation also contains '.implode(', ', $others).'.');
        }
    }
}
