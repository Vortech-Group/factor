<?php

declare(strict_types=1);

namespace Vortech\Factor;

use InvalidArgumentException;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Numbers\RoundingMode;

/**
 * The settings every calculation runs with.
 *
 * `precision` is the number of decimal places a transcendental result (sin, ln, exp, ...) is computed to
 * and the accuracy iterative methods converge to. Exact arithmetic ignores it. Internally the package works
 * with extra guard digits, so the places asked for are trustworthy, and a final rounding to fewer places
 * (money: 2) is a separate, explicit step.
 */
final class Context
{
    private static ?self $current = null;

    public function __construct(
        public readonly int $precision = 40,
        public readonly RoundingMode $rounding = RoundingMode::HalfAwayFromZero,
        public readonly int $maxIterations = 200,
        public readonly int $maxLength = 10_000,
        public readonly int $maxDepth = 128,
    ) {
        if ($precision < 1 || $precision > 1_000) {
            throw new InvalidArgumentException('The precision must be between 1 and 1000 decimal places.');
        }

        if ($maxIterations < 1 || $maxLength < 1 || $maxDepth < 1) {
            throw new InvalidArgumentException('The limits must be positive.');
        }
    }

    /**
     * The context used when a call is not given one.
     */
    public static function current(): self
    {
        return self::$current ??= new self;
    }

    public static function use(self $context): void
    {
        self::$current = $context;
    }

    public function withPrecision(int $precision): self
    {
        return new self($precision, $this->rounding, $this->maxIterations, $this->maxLength, $this->maxDepth);
    }

    public function withRounding(RoundingMode $rounding): self
    {
        return new self($this->precision, $rounding, $this->maxIterations, $this->maxLength, $this->maxDepth);
    }

    /**
     * The smallest difference that still counts: 10^-precision.
     */
    public function tolerance(): Number
    {
        return Number::one()->div(Number::of(10)->pow($this->precision));
    }
}
