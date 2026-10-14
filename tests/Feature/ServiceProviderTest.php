<?php

declare(strict_types=1);

use Vortech\Factor\Context;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Facades\Factor as FactorFacade;
use Vortech\Factor\Factor;
use Vortech\Factor\Numbers\RoundingMode;

afterEach(fn () => Context::use(new Context));

it('binds the factor and its context from the configuration', function () {
    expect(app(Factor::class))->toBeInstanceOf(Factor::class)
        ->and(app(Context::class)->precision)->toBe(40)
        ->and(app(Context::class)->rounding)->toBe(RoundingMode::HalfAwayFromZero)
        ->and(Context::current())->toBe(app(Context::class));
});

it('reads the precision and the limits from the configuration', function () {
    config([
        'factor.precision' => 12,
        'factor.rounding' => 'half-even',
        'factor.max_length' => 50,
    ]);
    app()->forgetInstance(Context::class);
    app()->forgetInstance(Factor::class);

    $context = app(Context::class);

    expect($context->precision)->toBe(12)
        ->and($context->rounding)->toBe(RoundingMode::HalfEven)
        ->and($context->maxLength)->toBe(50)
        ->and(app(Factor::class)->context()->precision)->toBe(12);
});

it('is available as a facade', function () {
    expect(FactorFacade::derivative('x^3 + 2x')->toString())->toBe('3*x^2 + 2')
        ->and(FactorFacade::integrate('x^2', 0, 3)->toDecimal(10))->toBe('9.0000000000')
        ->and(FactorFacade::solve('x^2 - 5x + 6 = 0')->values())->toHaveCount(2)
        ->and((string) FactorFacade::number('0.1')->add('0.2'))->toBe('0.3')
        ->and(FactorFacade::withPrecision(5)->context()->precision)->toBe(5);
});

it('uses the configured context for expressions built anywhere', function () {
    expect(Expression::parse('pi')->evaluate()->toDecimal(40))->toBe('3.1415926535897932384626433832795028841972');
});

it('works with a per-call precision', function () {
    $factor = app(Factor::class)->withPrecision(10);

    expect($factor->evaluate('sqrt(2)')->toDecimal(10))->toBe('1.4142135624')
        ->and($factor->context()->precision)->toBe(10)
        ->and(app(Factor::class)->context()->precision)->toBe(40);
});

it('publishes its config file', function () {
    expect(config('factor'))->toBeArray()
        ->and(config('factor.max_iterations'))->toBe(200);
});

it('chains from the result of one operation into the next', function () {
    $integral = FactorFacade::derivative('x^3 * sin(x)')->integrate(0, 1);

    // the integral of the derivative is f(1) - f(0) = sin(1)
    expect($integral->toDecimal(30))->toBe(FactorFacade::evaluate('sin(1)')->toDecimal(30))
        ->and(FactorFacade::derivative('x^3 - 3x')->solve()->values())->toHaveCount(2)
        ->and(FactorFacade::derivative('sin(x)')->root(between: [1, 2])->toDecimal(10))->toBe('1.5707963268');
});
