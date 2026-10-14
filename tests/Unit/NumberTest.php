<?php

declare(strict_types=1);

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Numbers\RoundingMode;

it('does exact decimal arithmetic', function () {
    expect((string) n('0.1')->add('0.2'))->toBe('0.3')
        ->and((string) n('1/3')->add('1/6'))->toBe('0.5')
        ->and((string) n('1/3')->mul(3))->toBe('1')
        ->and((string) n('1.1')->mul('1.1'))->toBe('1.21')
        ->and(n('0.1')->add('0.2')->equals('0.3'))->toBeTrue()
        ->and((string) n(1)->div(3))->toBe('1/3');
});

it('reads integers, decimals, exponents, fractions and floats', function (mixed $input, string $expected) {
    expect((string) n($input))->toBe($expected);
})->with([
    [42, '42'],
    ['-17', '-17'],
    ['0.125', '0.125'],
    ['.5', '0.5'],
    ['1.5e-3', '0.0015'],
    ['2e3', '2000'],
    ['6/4', '1.5'],
    ['-1/3', '-1/3'],
    [0.1, '0.1'],
    [-2.5, '-2.5'],
    [1.0E-10, '0.0000000001'],
]);

it('rejects malformed input', function (string $input) {
    expect(fn () => Number::of($input))->toThrow(DomainException::class);
})->with(['', 'abc', '1.2.3', '1e', '--1', '1/0', '1e5000']);

it('rejects NaN and infinity', function () {
    expect(fn () => Number::of(NAN))->toThrow(DomainException::class)
        ->and(fn () => Number::of(INF))->toThrow(DomainException::class);
});

it('keeps fractions in lowest terms with the sign on the numerator', function () {
    $number = Number::fraction(-6, -8);

    expect($number->numerator)->toBe('3')->and($number->denominator)->toBe('4')
        ->and(Number::fraction(3, -9)->numerator)->toBe('-1')
        ->and(Number::fraction(0, 5)->denominator)->toBe('1');
});

it('rejects division by zero', function () {
    expect(fn () => n(1)->div(0))->toThrow(DomainException::class)
        ->and(fn () => n(0)->pow(-1))->toThrow(DomainException::class);
});

it('raises to integer powers exactly', function () {
    expect((string) n('1.05')->pow(2))->toBe('1.1025')
        ->and((string) n(2)->pow(-3))->toBe('0.125')
        ->and((string) n('2/3')->pow(3))->toBe('8/27')
        ->and((string) n(7)->pow(0))->toBe('1')
        ->and(fn () => n(2)->pow(Number::MAX_EXPONENT + 1))->toThrow(DomainException::class);
});

it('compares numbers', function () {
    expect(n('1/3')->compare('0.3333'))->toBe(1)
        ->and(n(-1)->lessThan(0))->toBeTrue()
        ->and(n('2/4')->equals('0.5'))->toBeTrue()
        ->and(n(2)->max(3)->equals(3))->toBeTrue()
        ->and(n(-2)->abs()->equals(2))->toBeTrue()
        ->and(n(-5)->sign())->toBe(-1)
        ->and(n(0)->sign())->toBe(0);
});

it('rounds with every mode', function (string $value, RoundingMode $mode, string $expected) {
    expect(n($value)->toDecimal(0, $mode))->toBe($expected);
})->with([
    ['2.5', RoundingMode::HalfAwayFromZero, '3'],
    ['-2.5', RoundingMode::HalfAwayFromZero, '-3'],
    ['2.4', RoundingMode::HalfAwayFromZero, '2'],
    ['2.5', RoundingMode::HalfEven, '2'],
    ['3.5', RoundingMode::HalfEven, '4'],
    ['-2.5', RoundingMode::HalfEven, '-2'],
    ['2.6', RoundingMode::HalfEven, '3'],
    ['2.9', RoundingMode::Truncate, '2'],
    ['-2.9', RoundingMode::Truncate, '-2'],
    ['2.1', RoundingMode::Ceiling, '3'],
    ['-2.1', RoundingMode::Ceiling, '-2'],
    ['2.9', RoundingMode::Floor, '2'],
    ['-2.1', RoundingMode::Floor, '-3'],
    ['2.1', RoundingMode::AwayFromZero, '3'],
    ['-2.1', RoundingMode::AwayFromZero, '-3'],
]);

it('formats decimals with a fixed number of places', function () {
    expect(n('-1/3')->toDecimal(5))->toBe('-0.33333')
        ->and(n('2/3')->toDecimal(5))->toBe('0.66667')
        ->and(n('-0.004')->toDecimal(2))->toBe('0.00')
        ->and(n('1234.5')->toDecimal(0))->toBe('1235')
        ->and(n('0.005')->toDecimal(2))->toBe('0.01')
        ->and(n('1.005')->toDecimal(2, RoundingMode::HalfEven))->toBe('1.00')
        ->and(n(7)->toDecimal(3))->toBe('7.000');
});

it('rounds to a number of places exactly', function () {
    expect((string) n('1.23456')->round(2))->toBe('1.23')
        ->and((string) n('1234')->round(-2))->toBe('1200')
        ->and((string) n('1250')->round(-2))->toBe('1300')
        ->and((string) n('1/3')->round(3))->toBe('0.333');
});

it('floors and ceils', function () {
    expect((string) n('-7/2')->floor())->toBe('-4')
        ->and((string) n('-7/2')->ceil())->toBe('-3')
        ->and((string) n('7/2')->floor())->toBe('3');
});

it('finds exact square roots', function () {
    expect((string) n('9/4')->exactSqrt())->toBe('1.5')
        ->and(n(2)->exactSqrt())->toBeNull()
        ->and(n(-4)->exactSqrt())->toBeNull();
});

it('converts to int and float', function () {
    expect(n(42)->toInt())->toBe(42)
        ->and(n('1/2')->toInt())->toBeNull()
        ->and(n('99999999999999999999')->toInt())->toBeNull()
        ->and(n('0.1')->toFloat())->toBe(0.1);
});

it('knows when its decimal expansion ends', function () {
    expect(n('3/8')->isTerminating())->toBeTrue()->and(n('1/3')->isTerminating())->toBeFalse();
});
