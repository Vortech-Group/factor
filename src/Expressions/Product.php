<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Context;
use Vortech\Factor\Numbers\Number;

final readonly class Product extends Expression
{
    private string $key;

    /**
     * @param  list<Expression>  $factors  at least two, in canonical order; build products with `Expression::times()`
     */
    public function __construct(public array $factors)
    {
        $this->key = '*('.implode(',', array_map(static fn (Expression $factor): string => $factor->key(), $factors)).')';
    }

    public function key(): string
    {
        return $this->key;
    }

    public function compute(array $variables, Context $context): Number
    {
        $product = Number::one();

        foreach ($this->factors as $factor) {
            $product = $product->mul($factor->compute($variables, $context));
        }

        return $product;
    }

    public function substitute(array $replacements): Expression
    {
        return Simplifier::product(array_map(static fn (Expression $factor): Expression => $factor->substitute($replacements), $this->factors));
    }

    public function simplify(): Expression
    {
        return Simplifier::product(array_map(static fn (Expression $factor): Expression => $factor->simplify(), $this->factors));
    }

    public function variables(): array
    {
        return Simplifier::variablesOf($this->factors);
    }
}
