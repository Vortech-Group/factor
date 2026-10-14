<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Numbers\Functions;
use Vortech\Factor\Numbers\Number;

/**
 * pi or e, kept symbolic so that results such as `pi*x` stay exact until they are evaluated.
 */
final readonly class Constant extends Expression
{
    public const NAMES = ['pi', 'e'];

    public function __construct(public string $name)
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new DomainException(sprintf('"%s" is not a known constant.', $name));
        }
    }

    public function key(): string
    {
        return 'c:'.$this->name;
    }

    public function compute(array $variables, Context $context): Number
    {
        $places = $context->precision + Simplifier::GUARD;

        return $this->name === 'pi' ? Functions::pi($places) : Functions::e($places);
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
