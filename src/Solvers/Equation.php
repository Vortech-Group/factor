<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\ParseException;
use Vortech\Factor\Exceptions\UnsolvableException;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Expressions\Parser\Parser;

/**
 * Turns an equation into the expression that is zero at its solutions.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class Equation
{
    /**
     * "left = right" becomes left - right; an expression or a text without "=" is taken to be equal to zero.
     *
     * @throws ParseException
     */
    public static function toExpression(Expression|string $equation, ?Context $context = null): Expression
    {
        if ($equation instanceof Expression) {
            return $equation;
        }

        if (str_contains($equation, '=')) {
            [$left, $right] = Parser::parseEquation($equation, $context);

            return $left->minus($right);
        }

        return Parser::parse($equation, $context);
    }

    /**
     * The variable to solve for: the one given, or the only one in the expression.
     *
     * @throws UnsolvableException
     */
    public static function variable(Expression $expression, ?string $variable): string
    {
        $variables = $expression->variables();

        if ($variable !== null) {
            return $variable;
        }

        if (count($variables) === 1) {
            return $variables[0];
        }

        throw new UnsolvableException($variables === []
            ? 'The equation has no variable.'
            : 'The equation has several variables ('.implode(', ', $variables).'), say which one to solve for.');
    }
}
