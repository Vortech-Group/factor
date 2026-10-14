<?php

declare(strict_types=1);

namespace Vortech\Factor\Calculus;

use LogicException;
use Vortech\Factor\Expressions\Call;
use Vortech\Factor\Expressions\Constant;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Expressions\Num;
use Vortech\Factor\Expressions\Power;
use Vortech\Factor\Expressions\Product;
use Vortech\Factor\Expressions\Simplifier;
use Vortech\Factor\Expressions\Sum;
use Vortech\Factor\Expressions\Variable;
use Vortech\Factor\Numbers\Number;

/**
 * Symbolic differentiation: the sum, product, power and chain rules over the elementary functions.
 * The result is exact and simplified.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class Differentiator
{
    public static function derive(Expression $expression, string $variable): Expression
    {
        return match (true) {
            $expression instanceof Num, $expression instanceof Constant => self::number(0),
            $expression instanceof Variable => self::number($expression->name === $variable ? 1 : 0),
            $expression instanceof Sum => Simplifier::sum(array_map(
                static fn (Expression $term): Expression => self::derive($term, $variable),
                $expression->terms,
            )),
            $expression instanceof Product => self::product($expression, $variable),
            $expression instanceof Power => self::power($expression, $variable),
            $expression instanceof Call => self::call($expression, $variable),
            default => throw new LogicException('Unknown expression node.'),
        };
    }

    private static function number(int $value): Num
    {
        return new Num(Number::of($value));
    }

    private static function product(Product $product, string $variable): Expression
    {
        $terms = [];

        foreach (array_keys($product->factors) as $position) {
            $terms[] = Simplifier::product(array_map(
                static fn (Expression $factor, int $index): Expression => $index === $position ? self::derive($factor, $variable) : $factor,
                $product->factors,
                array_keys($product->factors),
            ));
        }

        return Simplifier::sum($terms);
    }

    private static function power(Power $power, string $variable): Expression
    {
        $base = $power->base;
        $exponent = $power->exponent;
        $baseDepends = in_array($variable, $base->variables(), true);
        $exponentDepends = in_array($variable, $exponent->variables(), true);

        if (! $baseDepends && ! $exponentDepends) {
            return self::number(0);
        }

        if (! $exponentDepends) {
            return Simplifier::product([
                $exponent,
                Simplifier::power($base, Simplifier::sum([$exponent, self::number(-1)])),
                self::derive($base, $variable),
            ]);
        }

        $exponentPart = Simplifier::product([self::derive($exponent, $variable), Simplifier::call('ln', $base)]);

        if (! $baseDepends) {
            return Simplifier::product([$power, $exponentPart]);
        }

        return Simplifier::product([
            $power,
            Simplifier::sum([
                $exponentPart,
                Simplifier::product([$exponent, self::derive($base, $variable), Simplifier::power($base, self::number(-1))]),
            ]),
        ]);
    }

    private static function call(Call $call, string $variable): Expression
    {
        $u = $call->argument;
        $inner = self::derive($u, $variable);

        if ($inner instanceof Num && $inner->value->isZero()) {
            return $inner;
        }

        $minusOne = self::number(-1);
        $oneMinusSquare = Simplifier::sum([self::number(1), Simplifier::product([$minusOne, Simplifier::power($u, self::number(2))])]);
        $onePlusSquare = Simplifier::sum([self::number(1), Simplifier::power($u, self::number(2))]);

        $outer = match ($call->name) {
            'sin' => Simplifier::call('cos', $u),
            'cos' => Simplifier::product([$minusOne, Simplifier::call('sin', $u)]),
            'tan' => Simplifier::power(Simplifier::call('cos', $u), self::number(-2)),
            'asin' => Simplifier::power($oneMinusSquare, new Num(Number::fraction(-1, 2))),
            'acos' => Simplifier::product([$minusOne, Simplifier::power($oneMinusSquare, new Num(Number::fraction(-1, 2)))]),
            'atan' => Simplifier::power($onePlusSquare, $minusOne),
            'sinh' => Simplifier::call('cosh', $u),
            'cosh' => Simplifier::call('sinh', $u),
            'tanh' => Simplifier::power(Simplifier::call('cosh', $u), self::number(-2)),
            'exp' => $call,
            default => Simplifier::power($u, $minusOne),
        };

        return Simplifier::product([$outer, $inner]);
    }
}
