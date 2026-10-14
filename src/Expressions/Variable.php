<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Number;

final readonly class Variable extends Expression
{
    public function __construct(public string $name)
    {
        if (preg_match('/^[\p{L}_][\p{L}\p{N}_]*$/u', $name) !== 1) {
            throw new DomainException(sprintf('"%s" is not a valid variable name.', $name));
        }

        if (in_array($name, Constant::NAMES, true)) {
            throw new DomainException(sprintf('"%s" is a constant, not a variable.', $name));
        }
    }

    public function key(): string
    {
        return 'v:'.$this->name;
    }

    public function compute(array $variables, Context $context): Number
    {
        return $variables[$this->name] ?? throw new DomainException(sprintf('The value of "%s" is missing.', $this->name));
    }

    public function substitute(array $replacements): Expression
    {
        return $replacements[$this->name] ?? $this;
    }

    public function simplify(): Expression
    {
        return $this;
    }

    public function variables(): array
    {
        return [$this->name];
    }
}
