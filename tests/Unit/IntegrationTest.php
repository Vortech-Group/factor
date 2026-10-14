<?php

declare(strict_types=1);

use Vortech\Factor\Calculus\IntegrationMethod;
use Vortech\Factor\Calculus\Integrator;
use Vortech\Factor\Context;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Numbers\Functions;
use Vortech\Factor\Results\IntegralResult;

function integral(string $function, string $from, string $to, IntegrationMethod $method = IntegrationMethod::GaussLegendre, int $precision = 30): IntegralResult
{
    $context = new Context(precision: $precision);

    return Integrator::integrate(
        Expression::parse($function)->toClosure(['x'], $context),
        Expression::parse($from)->evaluate([], $context),
        Expression::parse($to)->evaluate([], $context),
        $method,
        $context,
    );
}

it('integrates smooth functions to the full precision', function (string $function, string $from, string $to, string $expected) {
    $result = integral($function, $from, $to);

    expect($result->converged)->toBeTrue();
    expectClose($result->value, $expected, 29);
})->with([
    ['x^2', '0', '3', '9'],
    ['sin(x)', '0', 'pi', '2'],
    ['exp(x)', '0', '1', '1.718281828459045235360287471352662497757247'],
    ['4/(1+x^2)', '0', '1', '3.141592653589793238462643383279502884197169'],
    ['exp(-x^2)', '0', '1', '0.746824132812427025399467436131'],
    ['1/x', '1', 'e', '1'],
    ['cos(x)^2', '0', '2*pi', '3.141592653589793238462643383279502884197169'],
]);

it('integrates x ln x against its antiderivative', function () {
    // the antiderivative is x^2/2 ln(x) - x^2/4
    $expected = Functions::ln(n(3), 40)->mul('4.5')->sub(2);

    expectClose(integral('x*ln(x)', '1', '3')->value, $expected, 29);
});

it('integrates with the Romberg method too', function () {
    $result = integral('exp(x)', '0', '1', IntegrationMethod::Romberg, 15);

    expect($result->converged)->toBeTrue();
    expectClose($result->value, '1.718281828459045235360287471352662497757247', 14);
});

it('reports the error estimate and the evaluations', function () {
    $result = integral('sin(x)', '0', 'pi');

    expect($result->error->lessThanOrEqual(n('0.000000000000000000000000000001')))->toBeTrue()
        ->and($result->evaluations)->toBeGreaterThan(0)
        ->and($result->method)->toBe(IntegrationMethod::GaussLegendre);
});

it('flips the sign for reversed bounds and gives zero for equal ones', function () {
    expectClose(integral('x^2', '3', '0')->value, -9, 29);
    expect((string) integral('x^2', '2', '2')->value)->toBe('0');
});

it('handles a singularity at an end point and says when it could not converge', function () {
    $sqrt = integral('sqrt(x)', '0', '1', precision: 12);

    expectClose($sqrt->value, '2/3', 11);
    expect($sqrt->converged)->toBeTrue();
});

it('does not hide a failure to converge', function () {
    $context = new Context(precision: 8);
    $result = Integrator::integrate(
        Expression::parse('1/x')->toClosure(['x'], $context),
        n('0'),
        n('1'),
        IntegrationMethod::GaussLegendre,
        $context,
    );

    expect($result->converged)->toBeFalse()->and($result->error->greaterThan($context->tolerance()))->toBeTrue();
});

it('integrates a sum of integrals linearly', function () {
    $a = integral('3x^2 + 2x', '0', '2')->value;
    $b = integral('3x^2', '0', '2')->value->add(integral('2x', '0', '2')->value);

    expectClose($a, $b, 28);
    expectClose($a, 12, 28);
});
