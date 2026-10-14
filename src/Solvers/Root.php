<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Numbers\RoundingMode;

/**
 * A root of an equation: real or complex, with the exact closed form when there is one.
 */
final readonly class Root
{
    /**
     * @param  Expression|null  $expression  the exact value, for a real root with a closed form such as (-1 + sqrt(5))/2
     * @param  bool  $exact  true when the value is exactly known: a rational, or an `$expression`
     */
    public function __construct(
        public Number $real,
        public Number $imaginary,
        public ?Expression $expression,
        public bool $exact,
    ) {}

    public function isReal(): bool
    {
        return $this->imaginary->isZero();
    }

    /**
     * @param  int<0, max>  $scale
     */
    public function toDecimal(int $scale = 2, RoundingMode $mode = RoundingMode::HalfAwayFromZero): string
    {
        $real = $this->real->toDecimal($scale, $mode);

        if ($this->isReal()) {
            return $real;
        }

        $imaginary = $this->imaginary->abs()->toDecimal($scale, $mode);

        return $real.($this->imaginary->isNegative() ? ' - ' : ' + ').$imaginary.'i';
    }
}
