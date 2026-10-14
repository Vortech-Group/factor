<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Context;
use Vortech\Factor\Numbers\Number;

final readonly class Sum extends Expression
{
    private string $key;

    /**
     * @param  list<Expression>  $terms  at least two, in canonical order; build sums with `Expression::plus()`
     */
    public function __construct(public array $terms)
    {
        $this->key = '+('.implode(',', array_map(static fn (Expression $term): string => $term->key(), $terms)).')';
    }

    public function key(): string
    {
        return $this->key;
    }

    public function compute(array $variables, Context $context): Number
    {
        $total = Number::zero();

        foreach ($this->terms as $term) {
            $total = $total->add($term->compute($variables, $context));
        }

        return $total;
    }

    public function substitute(array $replacements): Expression
    {
        return Simplifier::sum(array_map(static fn (Expression $term): Expression => $term->substitute($replacements), $this->terms));
    }

    public function simplify(): Expression
    {
        return Simplifier::sum(array_map(static fn (Expression $term): Expression => $term->simplify(), $this->terms));
    }

    public function variables(): array
    {
        return Simplifier::variablesOf($this->terms);
    }
}
