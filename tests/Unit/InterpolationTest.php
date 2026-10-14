<?php

declare(strict_types=1);

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Factor;
use Vortech\Factor\Interpolation\InterpolationMethod;

function interpolate(array $x, array $y, InterpolationMethod $method)
{
    return (new Factor)->interpolate($x, $y, $method);
}

it('interpolates linearly and exactly', function () {
    $f = interpolate([0, 1, 3], [0, 10, 14], InterpolationMethod::Linear);

    expect((string) $f->at('0.5'))->toBe('5')
        ->and((string) $f->at(2))->toBe('12')
        ->and((string) $f->at(3))->toBe('14')
        ->and((string) $f->at('1/3'))->toBe('10/3');
});

it('passes through every point of the polynomial and the spline', function (InterpolationMethod $method) {
    $x = [0, 1, 2, 4, 7];
    $y = ['1', '3.5', '2', '-1', '8'];
    $f = interpolate($x, $y, $method);

    foreach ($x as $i => $at) {
        expect($f->at($at)->equals($y[$i]))->toBeTrue();
    }
})->with([InterpolationMethod::Polynomial, InterpolationMethod::CubicSpline]);

it('reproduces a polynomial of lower degree exactly', function () {
    // x^3 - 2x + 1 through 4 points
    $f = interpolate([0, 1, 2, 3], [1, 0, 5, 22], InterpolationMethod::Polynomial);

    expect((string) $f->at('1.5'))->toBe((string) n('1.5')->pow(3)->sub(3)->add(1))
        ->and((string) $f->at('2.5'))->toBe((string) n('2.5')->pow(3)->sub(5)->add(1));
});

it('has a natural cubic spline that reproduces a straight line', function () {
    $f = interpolate([0, 1, 2, 5], [1, 3, 5, 11], InterpolationMethod::CubicSpline);

    expect((string) $f->at('0.25'))->toBe('1.5')
        ->and((string) $f->at('3.5'))->toBe('8');
});

it('has a smooth spline close to the underlying function', function () {
    $x = range(0, 10);
    $y = array_map(fn ($v) => (string) (new Factor)->evaluate('x^2', ['x' => $v]), $x);
    $f = interpolate($x, $y, InterpolationMethod::CubicSpline);

    expectClose($f->at('4.5'), '20.25', 1);
    expect($f->at('4.5')->sub('20.25')->abs()->lessThan('0.05'))->toBeTrue();
});

it('refuses extrapolation and invalid points', function () {
    $f = interpolate([1, 2, 3], [1, 2, 3], InterpolationMethod::Linear);

    expect(fn () => $f->at('0.99'))->toThrow(DomainException::class, 'outside the range')
        ->and(fn () => $f->at(4))->toThrow(DomainException::class)
        ->and(fn () => interpolate([1, 1, 2], [1, 2, 3], InterpolationMethod::Linear))->toThrow(DomainException::class, 'strictly increasing')
        ->and(fn () => interpolate([1, 2], [1], InterpolationMethod::Linear))->toThrow(DomainException::class, 'one y')
        ->and(fn () => interpolate([1, 2], [1, 2], InterpolationMethod::CubicSpline))->toThrow(DomainException::class, 'At least 3');
});
