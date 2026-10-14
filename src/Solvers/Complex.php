<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Vortech\Factor\Numbers\Number;

/**
 * A complex number with exact rational parts, used while iterating towards the roots of a polynomial.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final readonly class Complex
{
    public function __construct(public Number $real, public Number $imaginary) {}

    public static function of(Number|int|string $real, Number|int|string $imaginary = 0): self
    {
        return new self(Number::of($real), Number::of($imaginary));
    }

    public function add(self $other): self
    {
        return new self($this->real->add($other->real), $this->imaginary->add($other->imaginary));
    }

    public function sub(self $other): self
    {
        return new self($this->real->sub($other->real), $this->imaginary->sub($other->imaginary));
    }

    public function mul(self $other): self
    {
        return new self(
            $this->real->mul($other->real)->sub($this->imaginary->mul($other->imaginary)),
            $this->real->mul($other->imaginary)->add($this->imaginary->mul($other->real)),
        );
    }

    public function div(self $other): self
    {
        $norm = $other->normSquared();

        return new self(
            $this->real->mul($other->real)->add($this->imaginary->mul($other->imaginary))->div($norm),
            $this->imaginary->mul($other->real)->sub($this->real->mul($other->imaginary))->div($norm),
        );
    }

    public function normSquared(): Number
    {
        return $this->real->mul($this->real)->add($this->imaginary->mul($this->imaginary));
    }

    public function round(int $places): self
    {
        return new self($this->real->round($places), $this->imaginary->round($places));
    }

    public function isZero(): bool
    {
        return $this->real->isZero() && $this->imaginary->isZero();
    }
}
