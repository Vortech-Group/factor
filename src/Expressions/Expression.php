<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Closure;
use Stringable;
use Vortech\Factor\Calculus\Differentiator;
use Vortech\Factor\Calculus\IntegrationMethod;
use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Exceptions\ParseException;
use Vortech\Factor\Expressions\Parser\Parser;
use Vortech\Factor\Factor;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Results\IntegralResult;
use Vortech\Factor\Results\RootResult;
use Vortech\Factor\Solvers\Solution;

/**
 * A mathematical expression: an immutable tree of numbers, variables, sums, products, powers and function calls.
 *
 * Every expression the package hands out is in a canonical simplified form, so `x + x` is `2*x`, `x*x` is `x^2`
 * and equal expressions compare equal. Subtraction is a sum with a negative term and division a product with a
 * negative power, which is why the tree has no separate nodes for them.
 */
abstract readonly class Expression implements Stringable
{
    /** The functions an expression may call. */
    public const FUNCTIONS = ['sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'sinh', 'cosh', 'tanh', 'exp', 'ln'];

    /**
     * An expression from an expression, a number, or a string to parse.
     *
     * @throws ParseException
     */
    public static function from(self|Number|int|float|string $value, ?Context $context = null): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof Number, is_int($value), is_float($value) => new Num(Number::of($value)),
            default => Parser::parse($value, $context),
        };
    }

    public static function number(Number|int|float|string $value): Num
    {
        return new Num(Number::of($value));
    }

    /**
     * @throws DomainException for an invalid name or the reserved names pi and e
     */
    public static function variable(string $name): Variable
    {
        return new Variable($name);
    }

    /**
     * @param  'pi'|'e'  $name
     *
     * @throws DomainException
     */
    public static function constant(string $name): Constant
    {
        return new Constant($name);
    }

    /**
     * A function call, simplified: `call('sin', $x)`.
     *
     * @throws DomainException for an unknown function
     */
    public static function call(string $name, self|Number|int|float|string $argument): self
    {
        return Simplifier::call($name, self::from($argument));
    }

    /**
     * @throws ParseException
     */
    public static function parse(string $input, ?Context $context = null): self
    {
        return Parser::parse($input, $context);
    }

    /**
     * A unique canonical string, equal for equal simplified expressions.
     */
    abstract public function key(): string;

    /**
     * @param  array<string, Number>  $variables
     */
    abstract public function compute(array $variables, Context $context): Number;

    /**
     * @param  array<string, self>  $replacements
     */
    abstract public function substitute(array $replacements): self;

    /**
     * Rebuilds the expression in canonical form.
     */
    abstract public function simplify(): self;

    /**
     * @return list<string> the variables in the expression, sorted
     */
    abstract public function variables(): array;

    /**
     * The value for the given variables, exact where the expression only uses arithmetic and integer powers,
     * and correct to the context's precision where it uses functions or roots.
     *
     * @param  array<string, Number|int|float|string>  $variables
     *
     * @throws DomainException when a variable is missing or the value is not defined
     */
    final public function evaluate(array $variables = [], ?Context $context = null): Number
    {
        $values = [];

        foreach ($variables as $name => $value) {
            $values[$name] = Number::of($value);
        }

        foreach ($this->variables() as $name) {
            if (! isset($values[$name])) {
                throw new DomainException(sprintf('The value of "%s" is missing.', $name));
            }
        }

        return $this->compute($values, $context ?? Context::current());
    }

    /**
     * A function from numbers to a number, taking the variables in the given order, for repeated evaluation.
     *
     * @param  list<string>  $order
     * @return Closure(Number|int|float|string ...): Number
     */
    final public function toClosure(array $order, ?Context $context = null): Closure
    {
        $context ??= Context::current();

        return function (Number|int|float|string ...$arguments) use ($order, $context): Number {
            $values = [];

            foreach ($order as $position => $name) {
                if (! array_key_exists($position, $arguments)) {
                    throw new DomainException(sprintf('The value of "%s" is missing.', $name));
                }

                $values[$name] = Number::of($arguments[$position]);
            }

            return $this->compute($values, $context);
        };
    }

    /**
     * Replaces variables by numbers or expressions and simplifies, so `substitute(['x' => '1/3'])` is exact.
     *
     * @param  array<string, self|Number|int|float|string>  $replacements
     */
    final public function with(array $replacements): self
    {
        $expressions = [];

        foreach ($replacements as $name => $value) {
            $expressions[$name] = self::from($value);
        }

        return $this->substitute($expressions);
    }

    /**
     * The derivative with respect to a variable, symbolic and exact.
     *
     * @param  positive-int  $order
     *
     * @throws DomainException for a function that has no derivative
     */
    final public function derivative(string $variable, int $order = 1): self
    {
        $result = $this;

        for ($i = 0; $i < $order; $i++) {
            $result = Differentiator::derive($result, $variable);
        }

        return $result;
    }

    /**
     * The partial derivatives with respect to each variable, keyed by name. Defaults to all variables.
     *
     * @param  list<string>|null  $variables
     * @return array<string, self>
     */
    final public function gradient(?array $variables = null): array
    {
        $gradient = [];

        foreach ($variables ?? $this->variables() as $name) {
            $gradient[$name] = $this->derivative($name);
        }

        return $gradient;
    }

    /**
     * The definite integral between two bounds, numerically: `Factor::derivative('x^3')->integrate(0, 1)`.
     *
     * @throws DomainException when the expression has several variables and none is named
     */
    final public function integrate(
        Number|int|float|string $from,
        Number|int|float|string $to,
        ?string $variable = null,
        IntegrationMethod $method = IntegrationMethod::GaussLegendre,
        ?Context $context = null,
    ): IntegralResult {
        return (new Factor($context))->integrate($this, $from, $to, $variable, $method);
    }

    /**
     * The solutions of `expression = 0` for a polynomial in one variable.
     */
    final public function solve(?string $variable = null, ?Context $context = null): Solution
    {
        return (new Factor($context))->solve($this, $variable);
    }

    /**
     * A root of `expression = 0` found numerically, from a starting point or inside an interval.
     *
     * @param  array{0: Number|int|float|string, 1: Number|int|float|string}|null  $between
     */
    final public function root(
        Number|int|float|string|null $near = null,
        ?array $between = null,
        ?string $variable = null,
        ?Context $context = null,
    ): RootResult {
        return (new Factor($context))->root($this, $variable, $near, $between);
    }

    final public function isConstant(): bool
    {
        return $this->variables() === [];
    }

    final public function equals(self|Number|int|float|string $other): bool
    {
        return $this->key() === self::from($other)->key();
    }

    final public function plus(self|Number|int|float|string $other): self
    {
        return Simplifier::sum([$this, self::from($other)]);
    }

    final public function minus(self|Number|int|float|string $other): self
    {
        return Simplifier::sum([$this, self::from($other)->negate()]);
    }

    final public function times(self|Number|int|float|string $other): self
    {
        return Simplifier::product([$this, self::from($other)]);
    }

    final public function dividedBy(self|Number|int|float|string $other): self
    {
        return Simplifier::product([$this, Simplifier::power(self::from($other), new Num(Number::of(-1)))]);
    }

    final public function pow(self|Number|int|float|string $exponent): self
    {
        return Simplifier::power($this, self::from($exponent));
    }

    final public function negate(): self
    {
        return Simplifier::product([new Num(Number::of(-1)), $this]);
    }

    final public function sqrt(): self
    {
        return Simplifier::power($this, new Num(Number::fraction(1, 2)));
    }

    final public function sin(): self
    {
        return Simplifier::call('sin', $this);
    }

    final public function cos(): self
    {
        return Simplifier::call('cos', $this);
    }

    final public function tan(): self
    {
        return Simplifier::call('tan', $this);
    }

    final public function asin(): self
    {
        return Simplifier::call('asin', $this);
    }

    final public function acos(): self
    {
        return Simplifier::call('acos', $this);
    }

    final public function atan(): self
    {
        return Simplifier::call('atan', $this);
    }

    final public function sinh(): self
    {
        return Simplifier::call('sinh', $this);
    }

    final public function cosh(): self
    {
        return Simplifier::call('cosh', $this);
    }

    final public function tanh(): self
    {
        return Simplifier::call('tanh', $this);
    }

    final public function exp(): self
    {
        return Simplifier::call('exp', $this);
    }

    final public function ln(): self
    {
        return Simplifier::call('ln', $this);
    }

    /**
     * The logarithm to a base: ln(x)/ln(base).
     */
    final public function log(self|Number|int|float|string $base): self
    {
        return $this->ln()->dividedBy(self::from($base)->ln());
    }

    final public function toString(): string
    {
        return Printer::string($this);
    }

    final public function toLatex(): string
    {
        return Printer::latex($this);
    }

    final public function __toString(): string
    {
        return $this->toString();
    }
}
