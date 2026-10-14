<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Expressions\Call;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Expressions\Num;
use Vortech\Factor\Expressions\Power;
use Vortech\Factor\Expressions\Product;
use Vortech\Factor\Expressions\Sum;
use Vortech\Factor\Expressions\Variable;
use Vortech\Factor\Numbers\Number;

/**
 * A polynomial in one variable with exact rational coefficients, lowest power first.
 */
final readonly class Polynomial
{
    /** The highest power an expression may be raised to while it is read as a polynomial. */
    private const MAX_POWER = 512;

    /**
     * @param  list<Number>  $coefficients  index = power, no trailing zeros
     */
    private function __construct(public array $coefficients) {}

    /**
     * @param  array<int, Number|int|string>  $coefficients  lowest power first
     */
    public static function of(array $coefficients): self
    {
        $numbers = array_map(Number::of(...), array_values($coefficients));

        while ($numbers !== [] && $numbers[array_key_last($numbers)]->isZero()) {
            array_pop($numbers);
        }

        return new self($numbers);
    }

    /**
     * The expression as a polynomial in the variable, or null when it is not one (a function of the variable, a
     * negative or fractional power, another variable or a constant such as pi among the coefficients).
     */
    public static function fromExpression(Expression $expression, string $variable): ?self
    {
        return match (true) {
            $expression instanceof Num => self::of([$expression->value]),
            $expression instanceof Variable => $expression->name === $variable ? self::of([0, 1]) : null,
            $expression instanceof Sum => self::combine($expression->terms, $variable, true),
            $expression instanceof Product => self::combine($expression->factors, $variable, false),
            $expression instanceof Power => self::fromPower($expression, $variable),
            $expression instanceof Call => null,
            default => null,
        };
    }

    public function degree(): int
    {
        return count($this->coefficients) - 1;
    }

    public function isZero(): bool
    {
        return $this->coefficients === [];
    }

    public function coefficient(int $power): Number
    {
        return $this->coefficients[$power] ?? Number::zero();
    }

    public function leading(): Number
    {
        return $this->coefficients === [] ? Number::zero() : $this->coefficients[array_key_last($this->coefficients)];
    }

    public function add(self $other): self
    {
        $result = [];

        for ($i = 0, $n = max(count($this->coefficients), count($other->coefficients)); $i < $n; $i++) {
            $result[] = $this->coefficient($i)->add($other->coefficient($i));
        }

        return self::of($result);
    }

    public function sub(self $other): self
    {
        return $this->add($other->scale(Number::of(-1)));
    }

    public function mul(self $other): self
    {
        if ($this->isZero() || $other->isZero()) {
            return self::of([]);
        }

        $result = array_fill(0, count($this->coefficients) + count($other->coefficients) - 1, Number::zero());

        foreach ($this->coefficients as $i => $a) {
            foreach ($other->coefficients as $j => $b) {
                $result[$i + $j] = $result[$i + $j]->add($a->mul($b));
            }
        }

        return self::of(array_values($result));
    }

    public function scale(Number $factor): self
    {
        return self::of(array_map(static fn (Number $c): Number => $c->mul($factor), $this->coefficients));
    }

    public function monic(): self
    {
        return $this->isZero() ? $this : $this->scale($this->leading()->reciprocal());
    }

    /**
     * @return array{self, self} quotient and remainder
     *
     * @throws DomainException when dividing by the zero polynomial
     */
    public function divide(self $divisor): array
    {
        if ($divisor->isZero()) {
            throw new DomainException('Division by the zero polynomial.');
        }

        $remainder = $this->coefficients;
        $quotient = array_fill(0, max(0, $this->degree() - $divisor->degree() + 1), Number::zero());

        for ($power = $this->degree() - $divisor->degree(); $power >= 0; $power--) {
            $factor = $remainder[$power + $divisor->degree()]->div($divisor->leading());
            $quotient[$power] = $factor;

            foreach ($divisor->coefficients as $i => $c) {
                $remainder[$power + $i] = $remainder[$power + $i]->sub($factor->mul($c));
            }
        }

        return [self::of($quotient), self::of($remainder)];
    }

    public function derivative(): self
    {
        $result = [];

        foreach ($this->coefficients as $power => $c) {
            if ($power > 0) {
                $result[] = $c->mul($power);
            }
        }

        return self::of($result);
    }

    public function evaluate(Number $x): Number
    {
        $result = Number::zero();

        foreach (array_reverse($this->coefficients) as $c) {
            $result = $result->mul($x)->add($c);
        }

        return $result;
    }

    /**
     * The monic greatest common divisor.
     */
    public function gcd(self $other): self
    {
        $a = $this;
        $b = $other;

        while (! $b->isZero()) {
            [, $remainder] = $a->divide($b);
            [$a, $b] = [$b, $remainder];
        }

        return $a->monic();
    }

    /**
     * The same roots, each once: the polynomial divided by the gcd with its derivative.
     */
    public function squarefree(): self
    {
        if ($this->degree() < 2) {
            return $this;
        }

        return $this->divide($this->gcd($this->derivative()))[0];
    }

    /**
     * @param  list<Expression>  $parts
     */
    private static function combine(array $parts, string $variable, bool $sum): ?self
    {
        $result = self::of($sum ? [] : [1]);

        foreach ($parts as $part) {
            $polynomial = self::fromExpression($part, $variable);

            if ($polynomial === null) {
                return null;
            }

            $result = $sum ? $result->add($polynomial) : $result->mul($polynomial);
        }

        return $result;
    }

    private static function fromPower(Power $power, string $variable): ?self
    {
        if (! $power->exponent instanceof Num) {
            return null;
        }

        $exponent = $power->exponent->value->toInt();

        if ($exponent === null || $exponent < 0 || $exponent > self::MAX_POWER) {
            return null;
        }

        $base = self::fromExpression($power->base, $variable);

        if ($base === null) {
            return null;
        }

        $result = self::of([1]);

        for ($i = 0; $i < $exponent; $i++) {
            $result = $result->mul($base);
        }

        return $result;
    }
}
