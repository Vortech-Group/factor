<?php

declare(strict_types=1);

namespace Vortech\Factor\Interpolation;

use Vortech\Factor\Numbers\Number;

final readonly class LinearInterpolator extends Interpolator
{
    protected function minimumPoints(): int
    {
        return 2;
    }

    protected function evaluate(Number $x): Number
    {
        $i = $this->interval($x);

        return $this->y[$i]->add(
            $this->y[$i + 1]->sub($this->y[$i])->mul($x->sub($this->x[$i]))->div($this->x[$i + 1]->sub($this->x[$i])),
        );
    }
}
