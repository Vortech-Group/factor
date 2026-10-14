<?php

declare(strict_types=1);

use Vortech\Factor\Context;
use Vortech\Factor\Expressions\Expression;

it('differentiates symbolically', function (string $function, string $expected) {
    expect(Expression::parse($function)->derivative('x')->toString())->toBe($expected);
})->with([
    ['5', '0'],
    ['x', '1'],
    ['y', '0'],
    ['x^2 + 3x + 2', '2*x + 3'],
    ['x^5', '5*x^4'],
    ['1/x', '-1/x^2'],
    ['sqrt(x)', '0.5/sqrt(x)'],
    ['3x^2 - sin(2x)/5', '6*x - 0.4*cos(2*x)'],
    ['x^3 * sin(x)', 'x^3*cos(x) + 3*x^2*sin(x)'],
    ['x^x', 'x^x*(ln(x) + 1)'],
    ['2^x', 'ln(2)*2^x'],
    ['exp(2x)', '2*exp(2*x)'],
    ['e^(-x^2)', '-2*x*exp(-x^2)'],
    ['ln(x^2 + 1)', '2*x/(x^2 + 1)'],
    ['atan(x)', '1/(x^2 + 1)'],
    ['tan(x)', '1/cos(x)^2'],
    ['pi * x', 'pi'],
    ['x*y^2', 'y^2'],
]);

it('differentiates several times', function () {
    $f = Expression::parse('x^4');

    expect($f->derivative('x', 2)->toString())->toBe('12*x^2')
        ->and($f->derivative('x', 4)->toString())->toBe('24')
        ->and($f->derivative('x', 5)->toString())->toBe('0')
        ->and(Expression::parse('sin(x)')->derivative('x', 4)->toString())->toBe('sin(x)');
});

it('takes partial derivatives and the gradient', function () {
    $f = Expression::parse('x^2*y + sin(y)');

    expect($f->derivative('x')->toString())->toBe('2*x*y')
        ->and($f->derivative('y')->toString())->toBe('x^2 + cos(y)')
        ->and(array_map('strval', $f->gradient()))->toBe(['x' => '2*x*y', 'y' => 'x^2 + cos(y)'])
        ->and($f->derivative('x')->derivative('y')->toString())->toBe('2*x');
});

// the symbolic derivative must agree with a central difference quotient
it('agrees with the numeric difference quotient', function (string $function, string $point) {
    $context = new Context(precision: 40);
    $expression = Expression::parse($function);
    $h = n('0.0000000000001');
    $x = n($point);

    $numeric = $expression->evaluate(['x' => $x->add($h)], $context)
        ->sub($expression->evaluate(['x' => $x->sub($h)], $context))
        ->div($h->mul(2));
    $symbolic = $expression->derivative('x')->evaluate(['x' => $x], $context);

    expectClose($symbolic, $numeric, 20);
})->with([
    ['x^3 - 2x + 1', '1.7'],
    ['sin(x)*cos(x)', '0.7'],
    ['exp(x)/(1+x^2)', '0.3'],
    ['ln(x)*x', '2.5'],
    ['sqrt(x^2+1)', '3'],
    ['atan(x)^2', '0.9'],
    ['x^x', '1.3'],
    ['(x+1)^(1/3)', '2'],
    ['tanh(x)*sinh(x)', '0.4'],
    ['asin(x/2)', '0.8'],
    ['acos(x/3)', '1.1'],
    ['2^x * 3^(-x)', '0.5'],
    ['cosh(x)^2', '0.6'],
    ['tan(x)/x', '0.5'],
    ['exp(-x^2/2)', '1.2'],
    ['1/(1+exp(-x))', '0.2'],
]);

it('evaluates a derivative at a point exactly where it can', function () {
    expect((string) Expression::parse('x^3 - 4x')->derivative('x')->evaluate(['x' => '1/3']))->toBe('-11/3');
});
