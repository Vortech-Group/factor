<?php

declare(strict_types=1);

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Number;

/**
 * @param  list<Number>  $parts
 */
function strings(array $parts): array
{
    return array_map('strval', array_map(fn (Number $part) => n($part->toDecimal(2)), $parts));
}

it('splits money so that the parts add up exactly', function () {
    expect(strings(n('100')->split(3)))->toBe(['33.34', '33.33', '33.33'])
        ->and(strings(n('0.05')->split(2)))->toBe(['0.03', '0.02'])
        ->and(strings(n('10')->split(4)))->toBe(['2.5', '2.5', '2.5', '2.5'])
        ->and(strings(n('0.01')->split(3)))->toBe(['0.01', '0', '0']);
});

it('allocates by ratio with the largest remainder method', function () {
    // 100 in the ratio 1:1:1:... and a classic 5-way case
    expect(strings(n('100')->allocate([1, 2, 3])))->toBe(['16.67', '33.33', '50']);
    expect(strings(n('5')->allocate([3, 7])))->toBe(['1.5', '3.5']);
    expect(strings(n('0.05')->allocate([3, 7])))->toBe(['0.02', '0.03']);
    expect(strings(n('10')->allocate([0, 1, 0])))->toBe(['0', '10', '0']);
});

it('keeps the sum exact for any amount and ratios', function (string $amount, array $ratios, int $scale) {
    $parts = n($amount)->allocate($ratios, $scale);
    $sum = array_reduce($parts, fn (Number $carry, Number $part) => $carry->add($part), n(0));

    expect($sum->equals($amount))->toBeTrue();

    foreach ($parts as $part) {
        expect($part->round($scale)->equals($part))->toBeTrue();
    }
})->with([
    ['100', [1, 1, 1], 2],
    ['1234567.89', [17, 31, 52, 3], 2],
    ['-100', [1, 1, 1], 2],
    ['0.07', [1, 1, 1, 1, 1], 2],
    ['1', ['0.1', '0.2', '0.7'], 0],
    ['99999.999', [5, 5, 5], 3],
    ['1000', [1, 1, 1, 1, 1, 1, 1], 0],
]);

it('allocates negative amounts symmetrically', function () {
    expect(strings(n('-100')->split(3)))->toBe(['-33.34', '-33.33', '-33.33']);
});

it('refuses to lose money silently', function () {
    expect(fn () => n('0.001')->split(2))->toThrow(DomainException::class, 'round it first')
        ->and(fn () => n(1)->allocate([1, -1]))->toThrow(DomainException::class)
        ->and(fn () => n(1)->allocate([0, 0]))->toThrow(DomainException::class)
        ->and(fn () => n(1)->split(0))->toThrow(DomainException::class);
});
