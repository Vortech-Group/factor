<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\SingularMatrixException;
use Vortech\Factor\Exceptions\UnsolvableException;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Expressions\Num;
use Vortech\Factor\LinearAlgebra\Matrix;
use Vortech\Factor\Numbers\Number;

/**
 * Solves systems of linear equations exactly: the answer is rational, with no rounding at all.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class SystemSolver
{
    /**
     * @param  list<Expression|string>  $equations  each "left = right", or an expression that equals zero
     * @param  list<string>|null  $variables  the unknowns, in order; by default all variables found, sorted
     * @return array<string, Number>
     *
     * @throws UnsolvableException when an equation is not linear in the unknowns
     * @throws SingularMatrixException when there is no unique solution
     */
    public static function solve(array $equations, ?array $variables = null, ?Context $context = null): array
    {
        $expressions = array_map(static fn (Expression|string $equation): Expression => Equation::toExpression($equation, $context), $equations);

        if ($variables === null) {
            $names = [];

            foreach ($expressions as $expression) {
                foreach ($expression->variables() as $name) {
                    $names[$name] = $name;
                }
            }

            $variables = array_values($names);
            sort($variables);
        }

        if (count($variables) !== count($expressions)) {
            throw new SingularMatrixException(sprintf('%d equations cannot determine %d unknowns uniquely.', count($expressions), count($variables)));
        }

        $zeros = array_fill_keys($variables, Expression::number(0));
        $matrix = [];
        $constants = [];

        foreach ($expressions as $expression) {
            $row = [];

            foreach ($variables as $name) {
                $coefficient = $expression->derivative($name);

                if (! $coefficient instanceof Num) {
                    throw new UnsolvableException('The system is not linear in '.$name.'.');
                }

                $row[] = $coefficient->value;
            }

            $constant = $expression->substitute($zeros);

            if (! $constant instanceof Num) {
                throw new UnsolvableException('The system contains an unknown that was not listed.');
            }

            $matrix[] = $row;
            $constants[] = $constant->value->negate();
        }

        $solution = Matrix::of($matrix)->solve($constants);

        return array_combine($variables, $solution);
    }
}
