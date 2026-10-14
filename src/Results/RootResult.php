<?php

declare(strict_types=1);

namespace Vortech\Factor\Results;

use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Numbers\RoundingMode;

/**
 * A root found by an iterative method.
 */
final readonly class RootResult
{
    public function __construct(
        public Number $root,
        public Number $residual,
        public int $iterations,
        public bool $converged,
        public string $method,
    ) {}

    /**
     * @param  int<0, max>  $scale
     */
    public function toDecimal(int $scale = 2, RoundingMode $mode = RoundingMode::HalfAwayFromZero): string
    {
        return $this->root->toDecimal($scale, $mode);
    }
}
