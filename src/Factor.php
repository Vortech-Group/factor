<?php

declare(strict_types=1);

namespace Vortech\Factor;

use Vortech\Factor\Calculus\IntegrationMethod;
use Vortech\Factor\Calculus\Integrator;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Exceptions\UnsolvableException;
use Vortech\Factor\Expressions\Constant;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Expressions\Variable;
use Vortech\Factor\Interpolation\CubicSplineInterpolator;
use Vortech\Factor\Interpolation\InterpolationMethod;
use Vortech\Factor\Interpolation\Interpolator;
use Vortech\Factor\Interpolation\LinearInterpolator;
use Vortech\Factor\Interpolation\PolynomialInterpolator;
use Vortech\Factor\LinearAlgebra\Matrix;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Results\IntegralResult;
use Vortech\Factor\Results\RootResult;
use Vortech\Factor\Solvers\Equation;
use Vortech\Factor\Solvers\EquationSolver;
use Vortech\Factor\Solvers\RootFinder;
use Vortech\Factor\Solvers\Solution;
use Vortech\Factor\Solvers\SystemSolver;

/**
 * The entry point: parse expressions, differentiate, integrate and solve, all with one precision.
 *
 * Arithmetic is exact (see `Number`), so only functions such as sin, ln and roots, and the numeric methods
 * (integration, root finding), are computed to `precision` decimal places.
 */
final readonly class Factor
{
    public function __construct(private ?Context $context = null) {}

    public function context(): Context
    {
        return $this->context ?? Context::current();
    }

    /**
     * The same operations at another number of decimal places.
     */
    public function withPrecision(int $precision): self
    {
        return new self($this->context()->withPrecision($precision));
    }

    public function number(Number|int|float|string $value): Number
    {
        return Number::of($value);
    }

    public function variable(string $name): Variable
    {
        return Expression::variable($name);
    }

    /**
     * @param  'pi'|'e'  $name
     */
    public function constant(string $name): Constant
    {
        return Expression::constant($name);
    }

    /**
     * An expression from text such as "3x^2 - sin(x)", a number or an expression.
     *
     * @throws Exceptions\ParseException
     */
    public function expression(Expression|Number|int|float|string $value): Expression
    {
        return Expression::from($value, $this->context());
    }

    /**
     * A function call such as `call('sin', $x)`.
     */
    public function call(string $function, Expression|Number|int|float|string $argument): Expression
    {
        return Expression::call($function, Expression::from($argument, $this->context()));
    }

    /**
     * @param  array<string, Number|int|float|string>  $variables
     */
    public function evaluate(Expression|string $expression, array $variables = []): Number
    {
        return $this->expression($expression)->evaluate($variables, $this->context());
    }

    /**
     * The symbolic derivative.
     *
     * @param  positive-int  $order
     */
    public function derivative(Expression|string $expression, ?string $variable = null, int $order = 1): Expression
    {
        $expression = $this->expression($expression);

        return $expression->derivative($this->variableOf($expression, $variable), $order);
    }

    /**
     * The value of the derivative at a point, computed from the exact symbolic derivative.
     *
     * @param  array<string, Number|int|float|string>|Number|int|float|string  $at  a value for the single variable, or values by name
     */
    public function derivativeAt(Expression|string $expression, array|Number|int|float|string $at, ?string $variable = null): Number
    {
        $expression = $this->expression($expression);
        $variable = $this->variableOf($expression, is_array($at) ? $variable : $variable);
        $values = is_array($at) ? $at : [$variable => $at];

        return $expression->derivative($variable)->evaluate($values, $this->context());
    }

    /**
     * The definite integral of an expression between two bounds, numerically.
     *
     * The bounds may be numbers or text such as "pi/2". The result carries an error estimate and whether the
     * method converged; the end points are finite and, for the default method, never evaluated.
     */
    public function integrate(
        Expression|string $expression,
        Number|int|float|string $from,
        Number|int|float|string $to,
        ?string $variable = null,
        IntegrationMethod $method = IntegrationMethod::GaussLegendre,
    ): IntegralResult {
        $expression = $this->expression($expression);
        $variable = $this->variableOf($expression, $variable);
        $others = array_values(array_diff($expression->variables(), [$variable]));

        if ($others !== []) {
            throw new DomainException('The integrand also contains '.implode(', ', $others).'; substitute values for them first.');
        }

        return Integrator::integrate(
            $expression->toClosure([$variable], $this->context()),
            $this->bound($from),
            $this->bound($to),
            $method,
            $this->context(),
        );
    }

    /**
     * Solves a polynomial equation: closed form for degrees 1 and 2, exact rational roots recognised for higher degrees.
     *
     * @throws UnsolvableException when it is not a polynomial equation in one variable
     */
    public function solve(Expression|string $equation, ?string $variable = null): Solution
    {
        $expression = Equation::toExpression($equation, $this->context());

        // a constant equation such as 0 = 0 or 1 = 0 has no variable to name
        $name = $variable ?? ($expression->variables() === [] ? 'x' : Equation::variable($expression, null));

        return EquationSolver::solve($expression, $name, $this->context());
    }

    /**
     * Finds a root of any equation numerically, from a starting point (Newton) or inside an interval where the
     * function changes sign.
     *
     * @param  array{0: Number|int|float|string, 1: Number|int|float|string}|null  $between
     *
     * @throws UnsolvableException
     */
    public function root(
        Expression|string $equation,
        ?string $variable = null,
        Number|int|float|string|null $near = null,
        ?array $between = null,
    ): RootResult {
        $expression = Equation::toExpression($equation, $this->context());
        $variable = Equation::variable($expression, $variable);

        if ($between !== null) {
            return RootFinder::brent($expression, $variable, $this->bound($between[0]), $this->bound($between[1]), $this->context());
        }

        if ($near === null) {
            throw new UnsolvableException('Give a starting point with `near` or an interval with `between`.');
        }

        return RootFinder::newton($expression, $variable, $this->bound($near), $this->context());
    }

    /**
     * Solves a system of linear equations exactly.
     *
     * @param  list<Expression|string>  $equations
     * @param  list<string>|null  $variables
     * @return array<string, Number>
     */
    public function system(array $equations, ?array $variables = null): array
    {
        return SystemSolver::solve($equations, $variables, $this->context());
    }

    /**
     * @param  array<array<Number|int|float|string>>  $rows
     */
    public function matrix(array $rows): Matrix
    {
        return Matrix::of($rows);
    }

    /**
     * A function through the points, exact in rational arithmetic. It answers between the first and the last x only.
     *
     * @param  array<int, Number|int|float|string>  $x  strictly increasing
     * @param  array<int, Number|int|float|string>  $y
     */
    public function interpolate(array $x, array $y, InterpolationMethod $method = InterpolationMethod::Linear): Interpolator
    {
        return match ($method) {
            InterpolationMethod::Linear => new LinearInterpolator($x, $y),
            InterpolationMethod::Polynomial => new PolynomialInterpolator($x, $y),
            InterpolationMethod::CubicSpline => new CubicSplineInterpolator($x, $y),
        };
    }

    private function variableOf(Expression $expression, ?string $variable): string
    {
        return Equation::variable($expression, $variable);
    }

    private function bound(Number|int|float|string $value): Number
    {
        return is_string($value)
            ? $this->expression($value)->evaluate([], $this->context())
            : Number::of($value);
    }
}
