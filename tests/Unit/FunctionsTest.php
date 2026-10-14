<?php

declare(strict_types=1);

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Functions;

// reference values are published constants, 50 places
it('computes the constants', function () {
    expect(Functions::pi(50)->toDecimal(50))->toBe('3.14159265358979323846264338327950288419716939937511')
        ->and(Functions::e(50)->toDecimal(50))->toBe('2.71828182845904523536028747135266249775724709369996');
});

it('computes elementary functions to the asked places', function (string $function, string $argument, string $expected) {
    $value = Functions::{$function}(n($argument), 40);

    expect($value->toDecimal(40))->toBe($expected);
})->with([
    ['sin', '1', '0.8414709848078965066525023216302989996226'],
    ['cos', '1', '0.5403023058681397174009366074429766037323'],
    ['tan', '1', '1.5574077246549022305069748074583601730873'],
    ['atan', '1', '0.7853981633974483096156608458198757210493'],
    ['asin', '0.5', '0.5235987755982988730771072305465838140329'],
    ['acos', '0.5', '1.0471975511965977461542144610931676280657'],
    ['exp', '1', '2.7182818284590452353602874713526624977572'],
    ['exp', '-10', '0.0000453999297624848515355915155605506102'],
    ['ln', '2', '0.6931471805599453094172321214581765680755'],
    ['ln', '10', '2.3025850929940456840179914546843642076011'],
    ['sinh', '1', '1.1752011936438014568823818505956008151557'],
    ['cosh', '1', '1.5430806348152437784779056207570616826015'],
    ['tanh', '1', '0.7615941559557648881194582826047935904128'],
    ['sqrt', '2', '1.4142135623730950488016887242096980785697'],
]);

it('reduces large arguments before the series', function () {
    expect(Functions::sin(n(100), 30)->toDecimal(30))->toBe('-0.506365641109758793656557610460')
        ->and(Functions::exp(n(50), 20)->toDecimal(20))->toBe('5184705528587072464087.45332293348538482747');
});

it('returns exact values where they are exact', function () {
    expect((string) Functions::sqrt(n('9/4'), 40))->toBe('1.5')
        ->and((string) Functions::sin(n(0), 40))->toBe('0')
        ->and((string) Functions::cos(n(0), 40))->toBe('1')
        ->and((string) Functions::exp(n(0), 40))->toBe('1')
        ->and((string) Functions::ln(n(1), 40))->toBe('0')
        ->and((string) Functions::pow(n('1.05'), n(30), 40))->toBe((string) n('1.05')->pow(30));
});

it('raises to fractional powers', function () {
    expect(Functions::pow(n(2), n('1/3'), 40)->toDecimal(40))->toBe('1.2599210498948731647672106072782283505703')
        ->and((string) Functions::pow(n(8), n('1/2'), 40) === (string) Functions::sqrt(n(8), 40))->toBeTrue()
        ->and((string) Functions::pow(n(-8), n('1/3'), 40) === '-'.Functions::pow(n(8), n('1/3'), 40))->toBeTrue()
        ->and((string) Functions::pow(n(4), n('3/2'), 40))->toBe('8')
        ->and((string) Functions::pow(n(0), n(2), 40))->toBe('0');
});

it('keeps sin squared plus cos squared equal to one', function (string $x) {
    $sin = Functions::sin(n($x), 45);
    $cos = Functions::cos(n($x), 45);

    expectClose($sin->mul($sin)->add($cos->mul($cos)), 1, 43);
})->with(['0.1', '1', '2.5', '3.14159', '-7', '123.456', '1000']);

it('inverts exp and ln', function (string $x) {
    expectClose(Functions::exp(Functions::ln(n($x), 50), 50), $x, 45);
})->with(['0.001', '0.5', '2', '10', '12345.678']);

it('rejects values outside the domain', function (callable $call) {
    expect($call)->toThrow(DomainException::class);
})->with([
    'sqrt of negative' => [fn () => Functions::sqrt(n(-1), 10)],
    'ln of zero' => [fn () => Functions::ln(n(0), 10)],
    'ln of negative' => [fn () => Functions::ln(n(-1), 10)],
    'asin out of range' => [fn () => Functions::asin(n(2), 10)],
    'zero to a negative power' => [fn () => Functions::pow(n(0), n(-1), 10)],
    'negative to a fractional power' => [fn () => Functions::pow(n(-4), n('1/2'), 10)],
    'huge exp' => [fn () => Functions::exp(n(10_000_000), 10)],
]);
