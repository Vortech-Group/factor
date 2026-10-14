<?php

declare(strict_types=1);

namespace Vortech\Factor\Interpolation;

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Number;

/**
 * A function through known points, evaluated in exact rational arithmetic. It only answers between the first
 * and the last x: extrapolating is a modelling decision the caller has to make explicitly.
 */
abstract readonly class Interpolator
{
    /** @var list<Number> */
    protected array $x;

    /** @var list<Number> */
    protected array $y;

    /**
     * @param  array<int, Number|int|float|string>  $x  strictly increasing
     * @param  array<int, Number|int|float|string>  $y  one value per x
     *
     * @throws DomainException for too few points, a different number of x and y, or an x that does not increase
     */
    public function __construct(array $x, array $y)
    {
        $this->x = array_values(array_map(Number::of(...), $x));
        $this->y = array_values(array_map(Number::of(...), $y));

        if (count($this->x) !== count($this->y)) {
            throw new DomainException('There must be exactly one y for every x.');
        }

        if (count($this->x) < $this->minimumPoints()) {
            throw new DomainException(sprintf('At least %d points are needed.', $this->minimumPoints()));
        }

        for ($i = 1; $i < count($this->x); $i++) {
            if ($this->x[$i]->lessThanOrEqual($this->x[$i - 1])) {
                throw new DomainException('The x values must be strictly increasing.');
            }
        }
    }

    /**
     * The value at x.
     *
     * @throws DomainException outside the range of the points
     */
    final public function at(Number|int|float|string $x): Number
    {
        $x = Number::of($x);

        $first = $this->x[0];
        $last = $this->x[count($this->x) - 1];

        if ($x->lessThan($first) || $x->greaterThan($last)) {
            throw new DomainException(sprintf('%s is outside the range %s to %s of the points.', $x, $first, $last));
        }

        return $this->evaluate($x);
    }

    abstract protected function minimumPoints(): int;

    abstract protected function evaluate(Number $x): Number;

    /**
     * The index i of the interval [x_i, x_i+1] that holds x.
     */
    protected function interval(Number $x): int
    {
        $last = count($this->x) - 2;

        for ($i = 0; $i < $last; $i++) {
            if ($x->lessThan($this->x[$i + 1])) {
                return $i;
            }
        }

        return $last;
    }
}
