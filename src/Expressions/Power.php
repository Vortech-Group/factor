<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Context;
use Vortech\Factor\Numbers\Functions;
use Vortech\Factor\Numbers\Number;

final readonly class Power extends Expression
{
    private string $key;

    public function __construct(public Expression $base, public Expression $exponent)
    {
        $this->key = '^('.$base->key().','.$exponent->key().')';
    }

    public function key(): string
    {
        return $this->key;
    }

    public function compute(array $variables, Context $context): Number
    {
        return Functions::pow(
            $this->base->compute($variables, $context),
            $this->exponent->compute($variables, $context),
            $context->precision + Simplifier::GUARD,
        );
    }

    public function substitute(array $replacements): Expression
    {
        return Simplifier::power($this->base->substitute($replacements), $this->exponent->substitute($replacements));
    }

    public function simplify(): Expression
    {
        return Simplifier::power($this->base->simplify(), $this->exponent->simplify());
    }

    public function variables(): array
    {
        return Simplifier::variablesOf([$this->base, $this->exponent]);
    }
}
