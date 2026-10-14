<?php

declare(strict_types=1);

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Exceptions\ParseException;
use Vortech\Factor\Expressions\Expression;

it('parses and prints in canonical form', function (string $input, string $expected) {
    expect(Expression::parse($input)->toString())->toBe($expected);
})->with([
    ['x^2+3x+2', 'x^2 + 3*x + 2'],
    ['2 + 3x + x^2', 'x^2 + 3*x + 2'],
    ['x*x', 'x^2'],
    ['x+x', '2*x'],
    ['x - x', '0'],
    ['(x+1)(x-1)', '(x - 1)*(x + 1)'],
    ['-x^2', '-x^2'],
    ['2^-3', '0.125'],
    ['2^3^2', '512'],
    ['x/(y*z)', 'x/(y*z)'],
    ['1/3*x', 'x/3'],
    ['sqrt(x)/x', '1/sqrt(x)'],
    ['x - (x+1)', '-1'],
    ['2(x+1)', '2*x + 2'],
    ['e^x', 'exp(x)'],
    ['exp(x)*exp(x)', 'exp(x)^2'],
    ['0.1+0.2', '0.3'],
    ['1/3 + 1/6', '0.5'],
    ['(x^2)^3', 'x^6'],
    ['(2x)^2', '4*x^2'],
    ['sin(-x)', '-sin(x)'],
    ['cos(-x)', 'cos(x)'],
    ['ln(e^x)', 'x'],
    ['exp(ln(x))', 'x'],
    ['x^0', '1'],
    ['x^1', 'x'],
    ['0*x', '0'],
    ['x/x', '1'],
    ['2x^2 / x', '2*x'],
    ['3 pi x', '3*pi*x'],
    ['x y', 'x*y'],
    ['xy', 'xy'],
    ['x**2', 'x^2'],
    ['arcsin(x)', 'asin(x)'],
    ['1e-3 x', '0.001*x'],
    ['log(x, 2)', 'ln(x)/ln(2)'],
    ['sqrt(16)', '4'],
    ['sqrt(2)', 'sqrt(2)'],
    ['cbrt(x)', 'x^(1/3)'],
    ['π', 'pi'],
]);

it('prints text that parses back to the same expression', function (string $input) {
    $expression = Expression::parse($input);

    expect(Expression::parse($expression->toString())->equals($expression))->toBeTrue();
})->with([
    'x^2+3x+2', '3x^2 - sin(2*x)/5', '(x+1)(x-1)', '-(1/3)x - 2/3', 'x^(1/3)*y^-2', '1/(x+1)^2', 'sqrt(x^2+1)/(x-1)',
    'e^(-x^2)', 'x^x', 'atan(x)/(1+x^2)', '2^x', '(a+b)^3', '1/sqrt(x)', 'x/3 + y/7', '0.5*ln(x)', '-x/(2y)',
]);

it('treats equal expressions as equal', function () {
    expect(Expression::parse('x + y')->equals('y + x'))->toBeTrue()
        ->and(Expression::parse('2x')->equals(Expression::variable('x')->times(2)))->toBeTrue()
        ->and(Expression::parse('x')->equals('y'))->toBeFalse();
});

it('rejects invalid input', function (string $input) {
    expect(fn () => Expression::parse($input))->toThrow(ParseException::class);
})->with([
    '', '1 +', 'sin', '(1+2', '1+2)', '1 2', '2 * * 3', 'sin x', 'sin()', 'sin(1, 2)', 'log(x)', '1/0', '@', 'x = 1', '1..2', 'f(x,)',
]);

it('limits the length and the nesting of the input', function () {
    $context = new Context(maxLength: 40, maxDepth: 10);

    expect(fn () => Expression::parse(str_repeat('x+', 30).'x', $context))->toThrow(ParseException::class, 'longer')
        ->and(fn () => Expression::parse(str_repeat('(', 12).'x'.str_repeat(')', 12), $context))->toThrow(ParseException::class, 'nested')
        ->and(fn () => Expression::parse(str_repeat('-', 15).'x', $context))->toThrow(ParseException::class, 'nested');
});

it('does not run code in the input', function () {
    expect(fn () => Expression::parse('system("ls")'))->toThrow(ParseException::class)
        ->and(fn () => Expression::parse('$a + 1'))->toThrow(ParseException::class)
        ->and(Expression::parse('system(2)')->toString())->toBe('2*system');
});

it('builds expressions fluently', function () {
    $x = Expression::variable('x');

    expect($x->pow(2)->plus($x->times(3))->plus(2)->toString())->toBe('x^2 + 3*x + 2')
        ->and($x->minus(1)->dividedBy($x->plus(1))->toString())->toBe('(x - 1)/(x + 1)')
        ->and($x->sqrt()->negate()->toString())->toBe('-sqrt(x)')
        ->and($x->sin()->pow(2)->plus($x->cos()->pow(2))->toString())->toBe('cos(x)^2 + sin(x)^2')
        ->and($x->log(10)->toString())->toBe('ln(x)/ln(10)')
        ->and(Expression::call('exp', 0)->toString())->toBe('1')
        ->and($x->plus('1/3')->toString())->toBe('x + 1/3');
});

it('rejects invalid variables, constants and functions', function () {
    expect(fn () => Expression::variable('2x'))->toThrow(DomainException::class)
        ->and(fn () => Expression::variable('pi'))->toThrow(DomainException::class)
        ->and(fn () => Expression::constant('tau'))->toThrow(DomainException::class)
        ->and(fn () => Expression::call('floor', 1))->toThrow(DomainException::class);
});

it('writes LaTeX', function (string $input, string $expected) {
    expect(Expression::parse($input)->toLatex())->toBe($expected);
})->with([
    ['x^2 + 3x', 'x^{2} + 3 x'],
    ['x/(y+1)', '\frac{x}{y + 1}'],
    ['sqrt(x)', '\sqrt{x}'],
    ['1/3 * x', '\frac{x}{3}'],
    ['sin(x)', '\sin\left(x\right)'],
    ['atan(x)', '\arctan\left(x\right)'],
    ['pi * r^2', '\pi r^{2}'],
    ['(x+1)^2', '\left(x + 1\right)^{2}'],
]);

it('evaluates exactly with rational arithmetic', function () {
    $expression = Expression::parse('x^3 - 2x/3 + 1/7');

    expect((string) $expression->evaluate(['x' => '1/2']))->toBe((string) n('1/8')->sub('1/3')->add('1/7'))
        ->and((string) Expression::parse('1.05^30')->evaluate())->toBe((string) n('1.05')->pow(30))
        ->and((string) Expression::parse('(1 + 0.04/12)^(-360)')->evaluate())->toBe((string) n(1)->add(n('0.04')->div(12))->pow(-360));
});

it('evaluates functions to the context precision', function () {
    $context = new Context(precision: 30);

    expect(Expression::parse('sin(x)^2 + cos(x)^2')->evaluate(['x' => 1], $context)->toDecimal(30))->toBe('1.000000000000000000000000000000')
        ->and(Expression::parse('pi*r^2')->evaluate(['r' => 2], $context)->toDecimal(30))->toBe('12.566370614359172953850573533118')
        ->and(Expression::parse('2^(1/2)')->evaluate([], $context)->toDecimal(30))->toBe('1.414213562373095048801688724210');
});

it('reports missing variables and domain errors', function () {
    expect(fn () => Expression::parse('x + y')->evaluate(['x' => 1]))->toThrow(DomainException::class, '"y"')
        ->and(fn () => Expression::parse('1/x')->evaluate(['x' => 0]))->toThrow(DomainException::class)
        ->and(fn () => Expression::parse('ln(x)')->evaluate(['x' => -1]))->toThrow(DomainException::class);
});

it('substitutes variables exactly', function () {
    $expression = Expression::parse('x^2 + y');

    expect($expression->with(['x' => 2])->toString())->toBe('y + 4')
        ->and($expression->with(['x' => 'y+1'])->toString())->toBe('(y + 1)^2 + y')
        ->and($expression->with(['x' => '1/3', 'y' => '2/3'])->toString())->toBe('7/9');
});

it('lists its variables', function () {
    expect(Expression::parse('z*x + sin(y) + pi')->variables())->toBe(['x', 'y', 'z'])
        ->and(Expression::parse('2+pi')->isConstant())->toBeTrue();
});

it('compiles to a closure', function () {
    $f = Expression::parse('x*y + 1')->toClosure(['x', 'y']);

    expect((string) $f(2, '0.5'))->toBe('2')
        ->and(fn () => $f(2))->toThrow(DomainException::class);
});
