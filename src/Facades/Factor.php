<?php

declare(strict_types=1);

namespace Vortech\Factor\Facades;

use Illuminate\Support\Facades\Facade;
use Vortech\Factor\Calculus\IntegrationMethod;
use Vortech\Factor\Context;
use Vortech\Factor\Expressions\Constant;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Expressions\Variable;
use Vortech\Factor\Interpolation\InterpolationMethod;
use Vortech\Factor\Interpolation\Interpolator;
use Vortech\Factor\LinearAlgebra\Matrix;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Results\IntegralResult;
use Vortech\Factor\Results\RootResult;
use Vortech\Factor\Solvers\Solution;

/**
 * @method static Context context()
 * @method static \Vortech\Factor\Factor withPrecision(int $precision)
 * @method static Number number(Number|int|float|string $value)
 * @method static Variable variable(string $name)
 * @method static Constant constant(string $name)
 * @method static Expression expression(Expression|Number|int|float|string $value)
 * @method static Expression call(string $function, Expression|Number|int|float|string $argument)
 * @method static Number evaluate(Expression|string $expression, array<string, Number|int|float|string> $variables = [])
 * @method static Expression derivative(Expression|string $expression, ?string $variable = null, int $order = 1)
 * @method static Number derivativeAt(Expression|string $expression, array<string, Number|int|float|string>|Number|int|float|string $at, ?string $variable = null)
 * @method static IntegralResult integrate(Expression|string $expression, Number|int|float|string $from, Number|int|float|string $to, ?string $variable = null, IntegrationMethod $method = IntegrationMethod::GaussLegendre)
 * @method static Solution solve(Expression|string $equation, ?string $variable = null)
 * @method static RootResult root(Expression|string $equation, ?string $variable = null, Number|int|float|string|null $near = null, ?array{0: Number|int|float|string, 1: Number|int|float|string} $between = null)
 * @method static array<string, Number> system(list<Expression|string> $equations, ?list<string> $variables = null)
 * @method static Interpolator interpolate(array<int, Number|int|float|string> $x, array<int, Number|int|float|string> $y, InterpolationMethod $method = InterpolationMethod::Linear)
 * @method static Matrix matrix(array<array<Number|int|float|string>> $rows)
 *
 * @see \Vortech\Factor\Factor
 */
final class Factor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vortech\Factor\Factor::class;
    }
}
