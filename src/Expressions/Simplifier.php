<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Number;

/**
 * The smart constructors of the expression tree. Each takes already simplified operands and returns the
 * canonical node: constants folded exactly, like terms and like powers combined, operands ordered.
 *
 * Only identities that hold for every real value they are defined for are applied. `x/x` becomes 1 and
 * `x^0` becomes 1 the way every algebra system does it (at x = 0 they were undefined to begin with).
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class Simplifier
{
    /** Extra decimal places the transcendental parts of an evaluation are computed with. */
    public const GUARD = 15;

    private const ODD = ['sin', 'tan', 'asin', 'atan', 'sinh', 'tanh'];

    /**
     * @param  list<Expression>  $terms
     */
    public static function sum(array $terms): Expression
    {
        $constant = Number::zero();
        /** @var array<string, array{Number, Expression}> $groups */
        $groups = [];

        foreach (self::flatten($terms, Sum::class) as $term) {
            if ($term instanceof Num) {
                $constant = $constant->add($term->value);

                continue;
            }

            [$coefficient, $rest] = self::split($term);
            $key = $rest->key();
            $groups[$key] = [($groups[$key][0] ?? Number::zero())->add($coefficient), $rest];
        }

        $items = [];

        foreach ($groups as [$coefficient, $rest]) {
            if (! $coefficient->isZero()) {
                $items[] = $coefficient->equals(1) ? $rest : self::product([new Num($coefficient), $rest]);
            }
        }

        if (! $constant->isZero()) {
            $items[] = new Num($constant);
        }

        usort($items, self::compareTerms(...));

        return match (count($items)) {
            0 => new Num(Number::zero()),
            1 => $items[0],
            default => new Sum($items),
        };
    }

    /**
     * @param  list<Expression>  $factors
     */
    public static function product(array $factors): Expression
    {
        $coefficient = Number::one();
        /** @var array<string, array{Expression, list<Expression>}> $groups */
        $groups = [];

        foreach (self::flatten($factors, Product::class) as $factor) {
            if ($factor instanceof Num) {
                $coefficient = $coefficient->mul($factor->value);

                if ($coefficient->isZero()) {
                    return new Num(Number::zero());
                }

                continue;
            }

            [$base, $exponent] = $factor instanceof Power ? [$factor->base, $factor->exponent] : [$factor, new Num(Number::one())];
            $groups[$base->key()] ??= [$base, []];
            $groups[$base->key()][1][] = $exponent;
        }

        $pieces = [];
        $distributed = false;

        foreach ($groups as [$base, $exponents]) {
            $piece = self::power($base, self::sum($exponents));

            if ($piece instanceof Num) {
                $coefficient = $coefficient->mul($piece->value);

                if ($coefficient->isZero()) {
                    return new Num(Number::zero());
                }
            } else {
                $distributed = $distributed || $piece instanceof Product;
                $pieces[] = $piece;
            }
        }

        if ($distributed) {
            return self::product([new Num($coefficient), ...$pieces]);
        }

        if ($pieces === []) {
            return new Num($coefficient);
        }

        usort($pieces, self::compareFactors(...));

        // a number times a lone sum is spread over the sum, so that x - (x + 1) cancels
        if (! $coefficient->equals(1) && count($pieces) === 1 && $pieces[0] instanceof Sum) {
            return self::sum(array_map(
                static fn (Expression $term): Expression => self::product([new Num($coefficient), $term]),
                $pieces[0]->terms,
            ));
        }

        if ($coefficient->equals(1)) {
            return count($pieces) === 1 ? $pieces[0] : new Product($pieces);
        }

        return new Product([new Num($coefficient), ...$pieces]);
    }

    public static function power(Expression $base, Expression $exponent): Expression
    {
        if ($base instanceof Constant && $base->name === 'e') {
            return self::call('exp', $exponent);
        }

        if ($exponent instanceof Num) {
            $power = $exponent->value;

            if ($power->isZero()) {
                return new Num(Number::one());
            }

            if ($power->equals(1)) {
                return $base;
            }

            if ($base instanceof Num) {
                $folded = self::foldNumbers($base->value, $power);

                if ($folded !== null) {
                    return new Num($folded);
                }
            } elseif ($power->isInteger() && $power->abs()->lessThanOrEqual(Number::MAX_EXPONENT)) {
                if ($base instanceof Power) {
                    return self::power($base->base, self::product([$base->exponent, $exponent]));
                }

                if ($base instanceof Product) {
                    return self::product(array_map(
                        static fn (Expression $factor): Expression => self::power($factor, $exponent),
                        $base->factors,
                    ));
                }
            }
        }

        if ($base instanceof Num && $base->value->equals(1)) {
            return $base;
        }

        return new Power($base, $exponent);
    }

    /**
     * @throws DomainException for an unknown function
     */
    public static function call(string $name, Expression $argument): Expression
    {
        if (! in_array($name, Expression::FUNCTIONS, true)) {
            throw new DomainException(sprintf('"%s" is not a known function.', $name));
        }

        if ($argument instanceof Num) {
            $special = self::specialValue($name, $argument->value);

            if ($special !== null) {
                return $special;
            }
        }

        if ($name === 'ln' && $argument instanceof Constant && $argument->name === 'e') {
            return new Num(Number::one());
        }

        if ($name === 'ln' && $argument instanceof Call && $argument->name === 'exp') {
            return $argument->argument;
        }

        if ($name === 'exp' && $argument instanceof Call && $argument->name === 'ln') {
            return $argument->argument;
        }

        if (self::isNegativeLeading($argument)) {
            $positive = self::product([new Num(Number::of(-1)), $argument]);

            if (in_array($name, self::ODD, true)) {
                return self::product([new Num(Number::of(-1)), self::call($name, $positive)]);
            }

            if ($name === 'cos' || $name === 'cosh') {
                return self::call($name, $positive);
            }
        }

        return new Call($name, $argument);
    }

    /**
     * @param  list<Expression>  $expressions
     * @return list<string>
     */
    public static function variablesOf(array $expressions): array
    {
        $names = [];

        foreach ($expressions as $expression) {
            foreach ($expression->variables() as $name) {
                $names[$name] = $name;
            }
        }

        $names = array_values($names);
        sort($names);

        return $names;
    }

    public static function isNegativeLeading(Expression $expression): bool
    {
        if ($expression instanceof Num) {
            return $expression->value->isNegative();
        }

        return $expression instanceof Product
            && $expression->factors[0] instanceof Num
            && $expression->factors[0]->value->isNegative();
    }

    /**
     * @param  list<Expression>  $items
     * @param  class-string<Sum|Product>  $type
     * @return list<Expression>
     */
    private static function flatten(array $items, string $type): array
    {
        $flat = [];

        foreach ($items as $item) {
            if ($item instanceof Sum && $type === Sum::class) {
                array_push($flat, ...self::flatten($item->terms, $type));
            } elseif ($item instanceof Product && $type === Product::class) {
                array_push($flat, ...self::flatten($item->factors, $type));
            } else {
                $flat[] = $item;
            }
        }

        return $flat;
    }

    /**
     * @return array{Number, Expression} the numeric coefficient of a term and the rest
     */
    private static function split(Expression $term): array
    {
        if ($term instanceof Product && $term->factors[0] instanceof Num) {
            $rest = array_slice($term->factors, 1);

            return [$term->factors[0]->value, count($rest) === 1 ? $rest[0] : new Product($rest)];
        }

        return [Number::one(), $term];
    }

    private static function foldNumbers(Number $base, Number $power): ?Number
    {
        if ($power->isInteger()) {
            $integer = $power->toInt();

            if ($integer === null || abs($integer) > Number::MAX_EXPONENT || ($base->isZero() && $integer < 0)) {
                return null;
            }

            return $base->pow($integer);
        }

        if ($power->denominator === '2' && ! $base->isZero()) {
            $root = $base->exactSqrt();
            $integer = Number::of($power->numerator)->toInt();

            if ($root !== null && $integer !== null && abs($integer) <= Number::MAX_EXPONENT) {
                return $root->pow($integer);
            }
        }

        return null;
    }

    private static function specialValue(string $name, Number $x): ?Expression
    {
        if ($x->isZero()) {
            return match ($name) {
                'sin', 'tan', 'asin', 'atan', 'sinh', 'tanh' => new Num(Number::zero()),
                'cos', 'cosh', 'exp' => new Num(Number::one()),
                default => null,
            };
        }

        if ($x->equals(1)) {
            return match ($name) {
                'ln' => new Num(Number::zero()),
                'exp' => new Constant('e'),
                default => null,
            };
        }

        return null;
    }

    private static function compareTerms(Expression $a, Expression $b): int
    {
        return [$a->variables() === [] ? 1 : 0, -self::degree($a), $a->key()]
            <=> [$b->variables() === [] ? 1 : 0, -self::degree($b), $b->key()];
    }

    private static function compareFactors(Expression $a, Expression $b): int
    {
        return [self::factorRank($a), self::factorBase($a)->key(), $a->key()]
            <=> [self::factorRank($b), self::factorBase($b)->key(), $b->key()];
    }

    private static function factorBase(Expression $factor): Expression
    {
        return $factor instanceof Power ? $factor->base : $factor;
    }

    private static function factorRank(Expression $factor): int
    {
        $base = self::factorBase($factor);

        return match (true) {
            $base instanceof Variable, $base instanceof Constant => 1,
            $base instanceof Sum => 3,
            default => 2,
        };
    }

    private static function degree(Expression $expression): float
    {
        return match (true) {
            $expression instanceof Variable => 1.0,
            $expression instanceof Power => $expression->exponent instanceof Num
                ? self::degree($expression->base) * $expression->exponent->value->toFloat()
                : 0.0,
            $expression instanceof Product => array_sum(array_map(self::degree(...), $expression->factors)),
            $expression instanceof Sum => max(0.0, ...array_map(self::degree(...), $expression->terms)),
            default => 0.0,
        };
    }
}
