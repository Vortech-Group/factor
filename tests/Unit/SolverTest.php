<?php

declare(strict_types=1);

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\SingularMatrixException;
use Vortech\Factor\Exceptions\UnsolvableException;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Factor;
use Vortech\Factor\LinearAlgebra\Matrix;
use Vortech\Factor\Solvers\Polynomial;

function factor(): Factor
{
    return new Factor(new Context(precision: 30));
}

it('solves linear and quadratic equations in closed form', function (string $equation, array $expected) {
    $roots = factor()->solve($equation)->roots();

    expect($roots->map(fn ($root) => (string) ($root->expression ?? $root->toDecimal(5)))->all())->toBe($expected);
})->with([
    ['3x + 6 = 0', ['-2']],
    ['0.1x = 0.03', ['0.3']],
    ['x^2 - 5x + 6 = 0', ['2', '3']],
    ['2x^2 - 3x/2 + 1/2 - 0.5', ['0', '0.75']],
    ['x^2 - 2 = 0', ['-sqrt(2)', 'sqrt(2)']],
    ['x^2 - x - 1 = 0', ['-0.5*sqrt(5) + 0.5', '0.5*sqrt(5) + 0.5']],
    ['x^2 = 0', ['0']],
    ['(x - 1)^2 (x + 2) = 0', ['-2', '1']],
]);

it('returns complex roots as conjugate pairs', function () {
    $solution = factor()->solve('x^2 + 2x + 5 = 0');

    expect($solution)->toHaveCount(2)
        ->and($solution->nth(1)->toDecimal(3))->toBe('-1.000 - 2.000i')
        ->and($solution->nth(2)->toDecimal(3))->toBe('-1.000 + 2.000i')
        ->and($solution->nth(1)->isReal())->toBeFalse()
        ->and($solution->nth(1)->exact)->toBeTrue()
        ->and(factor()->solve('x^2 + 2x + 5')->real())->toBeEmpty();
});

it('recognises rational roots of higher degree polynomials and divides them out', function () {
    $solution = factor()->solve('x^3 - 6x^2 + 11x - 6 = 0');

    expect($solution->roots()->map(fn ($r) => (string) $r->real)->all())->toBe(['1', '2', '3'])
        ->and($solution->roots()->map(fn ($r) => $r->exact)->all())->toBe([true, true, true]);

    $mixed = factor()->solve('x^3 - 2x^2 - 2x + 4');

    expect($mixed->roots()->map(fn ($r) => (string) ($r->expression ?? ''))->all())->toBe(['-sqrt(2)', 'sqrt(2)', '2']);

    expect(factor()->solve('2x^3 - 3x^2 - 2x')->roots()->map(fn ($r) => (string) $r->real)->all())->toBe(['-0.5', '0', '2']);
});

it('finds irrational and complex roots numerically at the full precision', function () {
    $solution = factor()->solve('x^3 - 2');

    expectClose($solution->nth(1)->real, '1.259921049894873164767210607278228350570251', 29);
    expect($solution->nth(1)->exact)->toBeFalse()->and($solution->nth(1)->isReal())->toBeTrue()
        ->and($solution)->toHaveCount(3);

    $plastic = factor()->solve('x^3 - x - 1')->nth(1);
    expectClose($plastic->real, '1.324717957244746025960908854478097340734404', 29);
});

it('has roots that satisfy the equation', function (string $equation) {
    $expression = Expression::parse($equation);

    foreach (factor()->solve($equation)->real() as $root) {
        expectClose($expression->evaluate(['x' => $root->real], new Context(precision: 40)), 0, 26);
    }
})->with(['x^5 - x - 1', 'x^4 - 10x^2 + 1', '3x^4 + x^3 - 2x + 7 - 7', 'x^6 - 1', 'x^3 + 3x^2 - 7x + 1']);

it('knows when every number or none is a solution', function () {
    expect(factor()->solve('x = x')->isAll())->toBeTrue()
        ->and(factor()->solve('0 = 0')->isAll())->toBeTrue()
        ->and(factor()->solve('x = x + 1')->isEmpty())->toBeTrue()
        ->and(factor()->solve('1 = 0')->isEmpty())->toBeTrue();
});

it('asks for the variable when it is ambiguous and refuses what it cannot solve', function () {
    expect(fn () => factor()->solve('x*y = 1'))->toThrow(UnsolvableException::class, 'several variables')
        ->and(fn () => factor()->solve('x*y = 1', 'x'))->toThrow(UnsolvableException::class)
        ->and(fn () => factor()->solve('sin(x) = 0.5'))->toThrow(UnsolvableException::class, 'Not a polynomial')
        ->and(fn () => factor()->solve('1/x = 2'))->toThrow(UnsolvableException::class);
});

it('finds roots numerically with Newton and with an interval', function () {
    $newton = factor()->root('cos(x) = x', near: 1);
    $bracket = factor()->root('cos(x) = x', between: [0, 1]);

    expect($newton->converged)->toBeTrue()->and($bracket->converged)->toBeTrue();
    expectClose($newton->root, '0.739085133215160641655312087673873404013411', 28);
    expectClose($bracket->root, '0.739085133215160641655312087673873404013411', 28);
    expectClose(factor()->root('x^3 - 2', near: 1)->root, '1.259921049894873164767210607278228350570251', 28);
    expectClose(factor()->root('exp(x) = 5', between: [0, 3])->root, '1.609437912434100374600759333226187639525601', 28);
});

it('finds roots with Brent in few steps, also near a flat or steep region', function (string $equation, array $between, string $expected) {
    $result = factor()->root($equation, between: $between);

    expect($result->converged)->toBeTrue()->and($result->method)->toBe('brent');
    expectClose($result->root, $expected, 27);
})->with([
    ['x^3 - 2', [1, 2], '1.259921049894873164767210607278228350570251'],
    ['exp(x) - 5', [0, 3], '1.609437912434100374600759333226187639525601'],
    ['x^9', [-1, 2], '0'],
    ['atan(x) - 1', [0, 5], '1.557407724654902230506974807458360173087250'],
    ['ln(x) + x', [0.1, 1], '0.567143290409783872999968662210355549753815'],
]);

it('refuses a bracket without a sign change and a flat start', function () {
    expect(fn () => factor()->root('x^2 + 1', between: [-1, 1]))->toThrow(UnsolvableException::class, 'same sign')
        ->and(fn () => factor()->root('x^2 - 2', near: 0))->toThrow(UnsolvableException::class, 'derivative is zero')
        ->and(fn () => factor()->root('x^2 - 2'))->toThrow(UnsolvableException::class, 'starting point');
});

it('computes polynomial arithmetic', function () {
    $p = Polynomial::fromExpression(Expression::parse('(x - 1)(x + 2)(x - 3)'), 'x');

    expect($p?->degree())->toBe(3)
        ->and((string) $p?->coefficient(0))->toBe('6')
        ->and((string) $p?->evaluate(n(3)))->toBe('0')
        ->and(Polynomial::fromExpression(Expression::parse('sin(x)'), 'x'))->toBeNull()
        ->and(Polynomial::fromExpression(Expression::parse('x^-1'), 'x'))->toBeNull()
        ->and(Polynomial::fromExpression(Expression::parse('x*y'), 'x'))->toBeNull();

    [$quotient, $remainder] = Polynomial::of([-1, 0, 1])->divide(Polynomial::of([-1, 1]));
    expect($quotient->coefficients)->toHaveCount(2)->and($remainder->isZero())->toBeTrue();
});

it('solves linear systems exactly', function () {
    $solution = factor()->system(['x + y = 3', 'x - y = 1']);

    expect(array_map('strval', $solution))->toBe(['x' => '2', 'y' => '1']);

    $three = factor()->system(['2x + 3y - z = 5', 'x - y + 2z = 1', '3x + y + z = 10']);
    expect(array_map('strval', $three))->toBe(['x' => '6.2', 'y' => '-4', 'z' => '-4.6']);

    $fractions = factor()->system(['3x + 2y = 1', 'x - y = 2/3']);
    expect(array_map('strval', $fractions))->toBe(['x' => '7/15', 'y' => '-0.2']);
});

it('refuses singular and non-linear systems', function () {
    expect(fn () => factor()->system(['x + y = 3', '2x + 2y = 6']))->toThrow(SingularMatrixException::class)
        ->and(fn () => factor()->system(['x + y = 3']))->toThrow(SingularMatrixException::class)
        ->and(fn () => factor()->system(['x*y = 3', 'x + y = 4']))->toThrow(UnsolvableException::class, 'not linear');
});

it('does exact matrix algebra', function () {
    $a = Matrix::of([[2, 1], [5, 3]]);

    expect($a->determinant()->equals(1))->toBeTrue()
        ->and($a->inverse()->toArray()[0][0]->equals(3))->toBeTrue()
        ->and($a->multiply($a->inverse())->toArray())->toEqual(Matrix::identity(2)->toArray())
        ->and($a->transpose()->get(0, 1)->equals(5))->toBeTrue()
        ->and(Matrix::of([[1, 2], [2, 4]])->rank())->toBe(1)
        ->and(Matrix::of([[1, 2], [2, 4]])->determinant()->isZero())->toBeTrue()
        ->and(Matrix::of([[0, 1], [1, 0]])->determinant()->equals(-1))->toBeTrue()
        ->and(Matrix::of([['1/3', 0], [0, 3]])->inverse()->get(0, 0)->equals(3))->toBeTrue()
        ->and(fn () => Matrix::of([[1, 2], [2, 4]])->inverse())->toThrow(SingularMatrixException::class);
});

it('returns a root by its position, counting from 1', function () {
    $solution = factor()->solve('x^2 - 5x + 6 = 0');

    expect((string) $solution->nth(1)->real)->toBe('2')
        ->and((string) $solution->nth(2)->real)->toBe('3')
        ->and(fn () => $solution->nth(3))->toThrow(\OutOfRangeException::class, 'no root number 3')
        ->and(fn () => $solution->nth(0))->toThrow(\OutOfRangeException::class);
});
