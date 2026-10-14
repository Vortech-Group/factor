<?php

declare(strict_types=1);

namespace Vortech\Factor\Interpolation;

use Vortech\Factor\Numbers\Number;

/**
 * The natural cubic spline: the second derivative is zero at both ends. The tridiagonal system for the second
 * derivatives is solved exactly.
 */
final readonly class CubicSplineInterpolator extends Interpolator
{
    /** @var list<Number> */
    private array $second;

    /**
     * @param  array<int, Number|int|float|string>  $x  strictly increasing
     * @param  array<int, Number|int|float|string>  $y  one value per x
     */
    public function __construct(array $x, array $y)
    {
        parent::__construct($x, $y);

        $this->second = $this->secondDerivatives();
    }

    protected function minimumPoints(): int
    {
        return 3;
    }

    /**
     * @return list<Number>
     */
    private function secondDerivatives(): array
    {
        $n = count($this->x);
        $second = array_fill(0, $n, Number::zero());
        $upper = array_fill(0, $n, Number::zero());
        $right = array_fill(0, $n, Number::zero());

        // Thomas algorithm on the interior points
        for ($i = 1; $i < $n - 1; $i++) {
            $left = $this->x[$i]->sub($this->x[$i - 1]);
            $next = $this->x[$i + 1]->sub($this->x[$i]);
            $diagonal = $left->add($next)->mul(2);
            $rhs = $this->y[$i + 1]->sub($this->y[$i])->div($next)->sub($this->y[$i]->sub($this->y[$i - 1])->div($left))->mul(6);

            $pivot = $diagonal->sub($left->mul($upper[$i - 1]));
            $upper[$i] = $next->div($pivot);
            $right[$i] = $rhs->sub($left->mul($right[$i - 1]))->div($pivot);
        }

        for ($i = $n - 2; $i >= 1; $i--) {
            $second[$i] = $right[$i]->sub($upper[$i]->mul($second[$i + 1]));
        }

        return array_values($second);
    }

    protected function evaluate(Number $x): Number
    {
        $i = $this->interval($x);
        $h = $this->x[$i + 1]->sub($this->x[$i]);
        $a = $this->x[$i + 1]->sub($x);
        $b = $x->sub($this->x[$i]);

        return $this->second[$i]->mul($a->pow(3))->div($h->mul(6))
            ->add($this->second[$i + 1]->mul($b->pow(3))->div($h->mul(6)))
            ->add($this->y[$i]->div($h)->sub($this->second[$i]->mul($h)->div(6))->mul($a))
            ->add($this->y[$i + 1]->div($h)->sub($this->second[$i + 1]->mul($h)->div(6))->mul($b));
    }
}
