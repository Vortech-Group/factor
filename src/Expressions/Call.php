<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Functions;
use Vortech\Factor\Numbers\Number;

final readonly class Call extends Expression
{
    public function __construct(public string $name, public Expression $argument)
    {
        if (! in_array($name, Expression::FUNCTIONS, true)) {
            throw new DomainException(sprintf('"%s" is not a known function.', $name));
        }
    }

    public function key(): string
    {
        return 'f:'.$this->name.'('.$this->argument->key().')';
    }

    public function compute(array $variables, Context $context): Number
    {
        $x = $this->argument->compute($variables, $context);
        $places = $context->precision + Simplifier::GUARD;

        return match ($this->name) {
            'sin' => Functions::sin($x, $places),
            'cos' => Functions::cos($x, $places),
            'tan' => Functions::tan($x, $places),
            'asin' => Functions::asin($x, $places),
            'acos' => Functions::acos($x, $places),
            'atan' => Functions::atan($x, $places),
            'sinh' => Functions::sinh($x, $places),
            'cosh' => Functions::cosh($x, $places),
            'tanh' => Functions::tanh($x, $places),
            'exp' => Functions::exp($x, $places),
            default => Functions::ln($x, $places),
        };
    }

    public function substitute(array $replacements): Expression
    {
        return Simplifier::call($this->name, $this->argument->substitute($replacements));
    }

    public function simplify(): Expression
    {
        return Simplifier::call($this->name, $this->argument->simplify());
    }

    public function variables(): array
    {
        return $this->argument->variables();
    }
}
