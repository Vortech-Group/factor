<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Context;
use Vortech\Factor\Numbers\Number;

final readonly class Num extends Expression
{
    private string $key;

    public function __construct(public Number $value)
    {
        $this->key = 'n:'.$value->numerator.'/'.$value->denominator;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function compute(array $variables, Context $context): Number
    {
        return $this->value;
    }

    public function substitute(array $replacements): Expression
    {
        return $this;
    }

    public function simplify(): Expression
    {
        return $this;
    }

    public function variables(): array
    {
        return [];
    }
}
