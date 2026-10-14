<?php

declare(strict_types=1);

use Vortech\Factor\Context;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Factor;

// a result asked for at 30 places must be what a computation at 60 places gives, rounded
it('gives the same digits at a lower precision as at a higher one', function (string $function, array $variables) {
    $expression = Expression::parse($function);

    $low = $expression->evaluate($variables, new Context(precision: 30))->toDecimal(30);
    $high = $expression->evaluate($variables, new Context(precision: 70))->toDecimal(30);

    expect($low)->toBe($high);
})->with([
    ['sin(x)*exp(x) + ln(x)', ['x' => '2.5']],
    ['atan(x)^2 + sqrt(x)', ['x' => '7']],
    ['x^(1/3) * 1000000', ['x' => '12345']],
    ['exp(-x)*100000000000', ['x' => '37']],
    ['cos(x)^2 - sin(x)', ['x' => '1000.5']],
    ['2^x', ['x' => '0.1']],
    ['tanh(x) + acos(x) + asin(x)', ['x' => '0.3']],
    ['(1 + 0.045/12)^(12*x) * 250000', ['x' => '30.5']],
]);

it('keeps money arithmetic exact', function () {
    $factor = new Factor(new Context(precision: 20));

    // 1.1 * 3 is exactly 3.3, and ten times 0.1 is exactly 1
    expect($factor->evaluate('1.1 * 3'))->toEqual(n('3.3'))
        ->and((string) $factor->evaluate('0.1 + 0.1 + 0.1 + 0.1 + 0.1 + 0.1 + 0.1 + 0.1 + 0.1 + 0.1'))->toBe('1')
        ->and($factor->evaluate('100000 * (1 + 0.05)^10')->toDecimal(2))->toBe('162889.46')
        ->and($factor->evaluate('(1 + 0.05)^10 * 100000')->toDecimal(15))->toBe('162889.462677744140625');
});

it('discounts an annuity to the exact present value', function () {
    $factor = new Factor(new Context(precision: 30));

    // 12 payments of 1000 at 0.5% a month: 1000 * (1 - v^12) / i with v = 1/1.005
    $present = $factor->evaluate('1000 * (1 - (1 + i)^-12) / i', ['i' => '0.005']);

    expect($present->toDecimal(2))->toBe('11618.93');
});

it('solves for the interest rate of an annuity', function () {
    $factor = new Factor(new Context(precision: 25));

    // the rate at which 12 payments of 1000 are worth 11618.93 today
    $rate = $factor->root('1000 * (1 - (1 + i)^-12) / i - 11618.93', near: '0.01');

    expect($rate->converged)->toBeTrue();
    expectClose($rate->root, '0.005', 6);
});
