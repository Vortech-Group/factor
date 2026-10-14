<?php

declare(strict_types=1);

namespace Vortech\Factor\Interpolation;

use Vortech\Factor\Numbers\Number;

/**
 * The Newton form of the interpolating polynomial. Many points at equal spacing make it swing wildly between the
 * points (Runge's phenomenon); prefer the cubic spline there.
 */
final readonly class PolynomialInterpolator extends Interpolator
{
    /** @var list<Number> */
    private array $coefficients;

    /**
     * @param  array<int, Number|int|float|string>  $x  strictly increasing
     * @param  array<int, Number|int|float|string>  $y  one value per x
     */
    public function __construct(array $x, array $y)
    {
        parent::__construct($x, $y);

        $this->coefficients = $this->dividedDifferences();
    }

    protected function minimumPoints(): int
    {
        return 1;
    }

    /**
     * @return list<Number>
     */
    private function dividedDifferences(): array
    {
        $table = $this->y;
        $coefficients = [$table[0]];
        $n = count($this->x);

        for ($level = 1; $level < $n; $level++) {
            for ($i = 0; $i < $n - $level; $i++) {
                $table[$i] = $table[$i + 1]->sub($table[$i])->div($this->x[$i + $level]->sub($this->x[$i]));
            }

            $coefficients[] = $table[0];
        }

        return $coefficients;
    }

    protected function evaluate(Number $x): Number
    {
        $result = $this->coefficients[count($this->coefficients) - 1];

        for ($i = count($this->coefficients) - 2; $i >= 0; $i--) {
            $result = $result->mul($x->sub($this->x[$i]))->add($this->coefficients[$i]);
        }

        return $result;
    }
}
