<?php

declare(strict_types=1);

namespace Vortech\Factor\Results;

use Vortech\Factor\Calculus\IntegrationMethod;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Numbers\RoundingMode;

/**
 * A definite integral: the value together with how far to trust it.
 */
final readonly class IntegralResult
{
    public function __construct(
        public Number $value,
        public Number $error,
        public int $evaluations,
        public bool $converged,
        public IntegrationMethod $method,
    ) {}

    /**
     * @param  int<0, max>  $scale
     */
    public function toDecimal(int $scale = 2, RoundingMode $mode = RoundingMode::HalfAwayFromZero): string
    {
        return $this->value->toDecimal($scale, $mode);
    }
}
