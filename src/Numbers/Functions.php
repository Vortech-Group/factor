<?php

declare(strict_types=1);

namespace Vortech\Factor\Numbers;

use Vortech\Factor\Exceptions\DomainException;

/**
 * Elementary functions at an arbitrary number of decimal places.
 *
 * Every function takes the number of decimal places `$places` and returns a result that is correct to all of
 * them (rounded, so the last place is off by at most half a unit). A result that is exact, such as
 * sqrt(9/4) or sin(0), is returned exactly. The work is done with guard digits and series that are
 * truncated by the working precision, never by an iteration count.
 */
final class Functions
{
    /** The largest magnitude of an argument to exp, to keep results from outgrowing memory. */
    private const MAX_EXP_ARGUMENT = 100_000;

    /** @var array<int, Number> */
    private static array $pi = [];

    /** @var array<int, Number> */
    private static array $ln2 = [];

    /**
     * @throws DomainException for a negative argument
     */
    public static function sqrt(Number $x, int $places): Number
    {
        if ($x->isNegative()) {
            throw new DomainException('The square root of a negative number is not real.');
        }

        $exact = $x->exactSqrt();

        if ($exact !== null) {
            return $exact;
        }

        $working = $places + 4;
        $scaled = $x->mul(Number::of(10)->pow(2 * $working))->floor();

        /** @var numeric-string $root */
        $root = bcsqrt($scaled->numerator, 0);

        return Number::fraction($root, bcpow('10', (string) $working, 0))->round($places);
    }

    public static function pi(int $places): Number
    {
        $working = $places + 8;

        return self::piExact($working)->round($places);
    }

    public static function e(int $places): Number
    {
        return self::exp(Number::one(), $places);
    }

    /**
     * @throws DomainException when the argument is too large
     */
    public static function exp(Number $x, int $places): Number
    {
        if ($x->isZero()) {
            return Number::one();
        }

        $magnitude = $x->abs();

        if ($magnitude->greaterThan(self::MAX_EXP_ARGUMENT)) {
            throw new DomainException('The argument of exp is too large.');
        }

        $halvings = 0;

        while ($magnitude->greaterThan('0.25')) {
            $magnitude = $magnitude->div(2);
            $halvings++;
        }

        $integerDigits = (int) ceil($x->abs()->toFloat() * 0.4343) + 1;
        $working = $places + 15 + $integerDigits + $halvings;

        $term = Number::one();
        $sum = Number::one();

        for ($n = 1; ; $n++) {
            $term = $term->mul($magnitude)->div($n)->round($working);

            if ($term->isZero()) {
                break;
            }

            $sum = $sum->add($term);
        }

        for ($i = 0; $i < $halvings; $i++) {
            $sum = $sum->mul($sum)->round($working);
        }

        if ($x->isNegative()) {
            $sum = $sum->reciprocal();
        }

        return $sum->round($places);
    }

    /**
     * @throws DomainException for zero and negative arguments
     */
    public static function ln(Number $x, int $places): Number
    {
        if (! $x->isPositive()) {
            throw new DomainException('The logarithm is only defined for positive numbers.');
        }

        if ($x->equals(1)) {
            return Number::zero();
        }

        $working = $places + 15 + max(strlen($x->numerator), strlen($x->denominator)) / 3;
        $working = (int) $working;

        // x = m * 2^exponent with m in [1/2, 1), where the series for atanh converges fast
        $exponent = (int) floor((strlen(ltrim($x->numerator, '-')) - strlen($x->denominator)) * 3.3219);
        $m = $x->div(Number::of(2)->pow($exponent));

        while ($m->greaterThanOrEqual(1)) {
            $m = $m->div(2);
            $exponent++;
        }

        while ($m->lessThan('0.5')) {
            $m = $m->mul(2);
            $exponent--;
        }

        $z = $m->sub(1)->div($m->add(1))->round($working);
        $result = self::atanhSeries($z, $working)->mul(2);

        if ($exponent !== 0) {
            $result = $result->add(self::ln2($working)->mul($exponent));
        }

        return $result->round($places);
    }

    public static function sin(Number $x, int $places): Number
    {
        return self::sinCos($x, $places, true);
    }

    public static function cos(Number $x, int $places): Number
    {
        return self::sinCos($x, $places, false);
    }

    /**
     * @throws DomainException where the cosine is zero
     */
    public static function tan(Number $x, int $places): Number
    {
        $working = $places + 15;
        $cos = self::sinCos($x, $working, false);

        if ($cos->abs()->lessThan(Number::of(10)->pow(-$places))) {
            throw new DomainException('The tangent is not defined here.');
        }

        return self::sinCos($x, $working, true)->div($cos)->round($places);
    }

    public static function atan(Number $x, int $places): Number
    {
        if ($x->isZero()) {
            return Number::zero();
        }

        $working = $places + 15;
        $negative = $x->isNegative();
        $a = $x->abs();
        $inverted = $a->greaterThan(1);

        if ($inverted) {
            $a = $a->reciprocal()->round($working);
        }

        for ($i = 0; $i < 3; $i++) {
            $a = $a->div(self::sqrt($a->mul($a)->add(1), $working)->add(1))->round($working);
        }

        $result = self::atanSeries($a, $working)->mul(8);

        if ($inverted) {
            $result = self::piExact($working)->div(2)->sub($result);
        }

        return ($negative ? $result->negate() : $result)->round($places);
    }

    /**
     * @throws DomainException outside [-1, 1]
     */
    public static function asin(Number $x, int $places): Number
    {
        if ($x->abs()->greaterThan(1)) {
            throw new DomainException('The arcsine is only defined between -1 and 1.');
        }

        $working = $places + 15;

        if ($x->abs()->equals(1)) {
            $half = self::piExact($working)->div(2);

            return ($x->isNegative() ? $half->negate() : $half)->round($places);
        }

        $root = self::sqrt(Number::one()->sub($x->mul($x)), $working);

        return self::atan($x->div($root), $working)->round($places);
    }

    /**
     * @throws DomainException outside [-1, 1]
     */
    public static function acos(Number $x, int $places): Number
    {
        $working = $places + 15;

        return self::piExact($working)->div(2)->sub(self::asin($x, $working))->round($places);
    }

    public static function sinh(Number $x, int $places): Number
    {
        $working = $places + 15;

        return self::exp($x, $working)->sub(self::exp($x->negate(), $working))->div(2)->round($places);
    }

    public static function cosh(Number $x, int $places): Number
    {
        $working = $places + 15;

        return self::exp($x, $working)->add(self::exp($x->negate(), $working))->div(2)->round($places);
    }

    public static function tanh(Number $x, int $places): Number
    {
        $working = $places + 15;
        $positive = self::exp($x, $working);
        $negative = self::exp($x->negate(), $working);

        return $positive->sub($negative)->div($positive->add($negative))->round($places);
    }

    /**
     * `$base` raised to `$exponent`: exact for an integer exponent, a square root of a perfect square and for
     * 0 and 1 bases, otherwise computed to `$places`.
     *
     * @throws DomainException when the result is not a real number
     */
    public static function pow(Number $base, Number $exponent, int $places): Number
    {
        if ($exponent->isInteger() && abs((float) $exponent->numerator) <= Number::MAX_EXPONENT) {
            return $base->pow((int) $exponent->numerator);
        }

        if ($base->isZero()) {
            if ($exponent->isPositive()) {
                return Number::zero();
            }

            throw new DomainException('Zero cannot be raised to a negative power.');
        }

        if ($base->equals(1)) {
            return Number::one();
        }

        if ($base->isNegative()) {
            if ($exponent->isInteger() || bcmod($exponent->denominator, '2', 0) === '0') {
                throw new DomainException('A negative number raised to this power is not real.');
            }

            $magnitude = self::pow($base->abs(), $exponent, $places);

            return bcmod($exponent->numerator, '2', 0) === '0' ? $magnitude : $magnitude->negate();
        }

        if ($exponent->denominator === '2' && abs((float) $exponent->numerator) <= 64) {
            $power = (int) $exponent->numerator;
            $root = self::sqrt($base, $places + 15 + 3 * abs($power));

            return $root->pow($power)->round($places);
        }

        $digits = strlen(ltrim($exponent->numerator, '-')) + 2;
        $logarithm = self::ln($base, $places + 15 + $digits + (int) ceil(abs($exponent->toFloat()) * 3));
        $product = $exponent->mul($logarithm);

        return self::exp($product, $places);
    }

    private static function sinCos(Number $x, int $places, bool $sine): Number
    {
        if ($x->isZero()) {
            return $sine ? Number::zero() : Number::one();
        }

        $working = $places + 15 + strlen(ltrim($x->numerator, '-')) - strlen($x->denominator) + 1;
        $working = max($places + 15, $working);
        $twoPi = self::piExact($working)->mul(2);

        $r = $x->sub($x->div($twoPi)->round(0)->mul($twoPi))->round($working);
        $r2 = $r->mul($r)->round($working);

        $term = $sine ? $r : Number::one();
        $sum = $term;

        for ($n = $sine ? 1 : 0; ; $n += 2) {
            $term = $term->mul($r2)->div(($n + 1) * ($n + 2))->negate()->round($working);

            if ($term->isZero()) {
                break;
            }

            $sum = $sum->add($term);
        }

        return $sum->round($places);
    }

    /**
     * The sum of z^(2k+1)/(2k+1), which is atanh(z) for |z| < 1.
     */
    private static function atanhSeries(Number $z, int $working): Number
    {
        $sum = $z;
        $power = $z;
        $square = $z->mul($z)->round($working);

        for ($k = 1; ; $k++) {
            $power = $power->mul($square)->round($working);
            $term = $power->div(2 * $k + 1)->round($working);

            if ($term->isZero()) {
                return $sum;
            }

            $sum = $sum->add($term);
        }
    }

    /**
     * The sum of (-1)^k x^(2k+1)/(2k+1), which is atan(x) for |x| <= 1.
     */
    private static function atanSeries(Number $x, int $working): Number
    {
        $sum = $x;
        $power = $x;
        $square = $x->mul($x)->round($working);

        for ($k = 1; ; $k++) {
            $power = $power->mul($square)->round($working);
            $term = $power->div(2 * $k + 1)->round($working);

            if ($term->isZero()) {
                return $sum;
            }

            $sum = $k % 2 === 1 ? $sum->sub($term) : $sum->add($term);
        }
    }

    private static function piExact(int $working): Number
    {
        return self::$pi[$working] ??= self::atanSeries(Number::fraction(1, 5), $working + 4)->mul(16)
            ->sub(self::atanSeries(Number::fraction(1, 239), $working + 4)->mul(4))
            ->round($working);
    }

    private static function ln2(int $working): Number
    {
        return self::$ln2[$working] ??= self::atanhSeries(Number::fraction(1, 3), $working + 4)->mul(2)->round($working);
    }
}
