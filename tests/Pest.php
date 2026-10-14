<?php

declare(strict_types=1);

use Vortech\Factor\Numbers\Number;
use Vortech\Factor\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function n(Number|int|float|string $value): Number
{
    return Number::of($value);
}

/**
 * Asserts that two numbers differ by less than 10^-$places.
 */
function expectClose(Number $actual, Number|int|float|string $expected, int $places): void
{
    $difference = $actual->sub($expected)->abs();

    expect($difference->lessThan(Number::of(10)->pow(-$places)))
        ->toBeTrue(sprintf('%s differs from %s by %s (allowed 1e-%d)', $actual->toDecimal(min($places + 5, 60)), Number::of($expected)->toDecimal(min($places + 5, 60)), $difference->toDecimal(min($places + 5, 60)), $places));
}
