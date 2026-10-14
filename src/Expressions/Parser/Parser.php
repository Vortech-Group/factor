<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions\Parser;

use Vortech\Factor\Context;
use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Exceptions\ParseException;
use Vortech\Factor\Expressions\Constant;
use Vortech\Factor\Expressions\Expression;
use Vortech\Factor\Expressions\Num;
use Vortech\Factor\Expressions\Simplifier;
use Vortech\Factor\Expressions\Variable;
use Vortech\Factor\Numbers\Number;

/**
 * Reads text such as `3x^2 - sin(2*x)/5` into an expression. A recursive descent parser over a small grammar: it never
 * evaluates anything and never runs code, and the length and nesting of the input are limited by the context.
 *
 * - numbers are exact: `0.1` is 1/10, `1e-3` is 1/1000
 * - operators: `+ - * / ^` (or `**`), `^` binds tighter than unary minus and is right associative
 * - implicit multiplication: `2x`, `2(x+1)`, `x y`, `(x+1)(x-1)`; a name is a whole word, so `xy` is one variable
 * - functions need parentheses: sin cos tan asin acos atan sinh cosh tanh exp ln log10 log2 sqrt cbrt, and `log(x, base)`
 * - constants: `pi` and `e`
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final class Parser
{
    private const ALIASES = ['arcsin' => 'asin', 'arccos' => 'acos', 'arctan' => 'atan'];

    private const EXTRA_FUNCTIONS = ['log', 'log10', 'log2', 'sqrt', 'cbrt'];

    /** @var list<array{string, string, int}> type, text, position */
    private array $tokens = [];

    private int $cursor = 0;

    private int $depth = 0;

    private function __construct(private readonly string $input, private readonly Context $context) {}

    /**
     * @throws ParseException
     */
    public static function parse(string $input, ?Context $context = null): Expression
    {
        $parser = new self($input, $context ?? Context::current());
        $parser->tokenize();
        $expression = $parser->expression();
        $parser->expect('end');

        return $expression;
    }

    /**
     * The two sides of an equation `left = right`.
     *
     * @return array{Expression, Expression}
     *
     * @throws ParseException
     */
    public static function parseEquation(string $input, ?Context $context = null): array
    {
        $parser = new self($input, $context ?? Context::current());
        $parser->tokenize();
        $left = $parser->expression();
        $parser->expect('=');
        $right = $parser->expression();
        $parser->expect('end');

        return [$left, $right];
    }

    /**
     * @throws ParseException
     */
    private function tokenize(): void
    {
        if (strlen($this->input) > $this->context->maxLength) {
            throw new ParseException(sprintf('The input is longer than %d characters.', $this->context->maxLength));
        }

        $length = strlen($this->input);
        $offset = 0;

        while ($offset < $length) {
            if (preg_match('/\G\s+/', $this->input, $m, 0, $offset) === 1) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $this->input, $m, 0, $offset) === 1) {
                $this->tokens[] = ['num', $m[0], $offset];
            } elseif (preg_match('/\G[\p{L}_][\p{L}\p{N}_]*/u', $this->input, $m, 0, $offset) === 1) {
                $this->tokens[] = ['id', $m[0], $offset];
            } elseif (preg_match('/\G\*\*|\G[-+*\/^(),=]/', $this->input, $m, 0, $offset) === 1) {
                $this->tokens[] = [$m[0] === '**' ? '^' : $m[0], $m[0], $offset];
            } else {
                throw new ParseException(sprintf('Unexpected character "%s" at position %d.', mb_substr(substr($this->input, $offset), 0, 1), $offset));
            }

            $offset += strlen($m[0]);
        }

        $this->tokens[] = ['end', '', $length];
    }

    /**
     * @throws ParseException
     */
    private function expression(): Expression
    {
        $this->enter();
        $left = $this->term();

        while ($this->peek() === '+' || $this->peek() === '-') {
            $operator = $this->next()[0];
            $right = $this->term();
            $left = Simplifier::sum([$left, $operator === '+' ? $right : $this->negate($right)]);
        }

        $this->depth--;

        return $left;
    }

    /**
     * @throws ParseException
     */
    private function term(): Expression
    {
        $left = $this->unary();

        while (true) {
            $type = $this->peek();

            if ($type === '*' || $type === '/') {
                $this->next();
                $right = $this->unary();
                $left = $type === '*'
                    ? Simplifier::product([$left, $right])
                    : Simplifier::product([$left, $this->reciprocal($right)]);
            } elseif ($type === 'id' || $type === '(') {
                $left = Simplifier::product([$left, $this->unary()]);
            } else {
                return $left;
            }
        }
    }

    /**
     * @throws ParseException
     */
    private function unary(): Expression
    {
        $type = $this->peek();

        if ($type === '-' || $type === '+') {
            $this->next();
            $this->enter();
            $operand = $this->unary();
            $this->depth--;

            return $type === '-' ? $this->negate($operand) : $operand;
        }

        return $this->power();
    }

    /**
     * @throws ParseException
     */
    private function power(): Expression
    {
        $base = $this->atom();

        if ($this->peek() === '^') {
            $this->next();
            $this->enter();
            $exponent = $this->unary();
            $this->depth--;

            return Simplifier::power($base, $exponent);
        }

        return $base;
    }

    /**
     * @throws ParseException
     */
    private function atom(): Expression
    {
        [$type, $text, $position] = $this->next();

        if ($type === 'num') {
            try {
                return new Num(Number::of($text));
            } catch (DomainException $e) {
                throw new ParseException($e->getMessage().' (at position '.$position.')', 0, $e);
            }
        }

        if ($type === '(') {
            $inner = $this->expression();
            $this->expect(')');

            return $inner;
        }

        if ($type === 'id') {
            if ($this->isFunction($text)) {
                if ($this->peek() !== '(') {
                    throw new ParseException(sprintf('The function %s needs parentheses: %s(...) (at position %d).', $text, $text, $position));
                }

                return $this->functionCall($text, $position);
            }

            return match ($text) {
                'pi', 'π' => new Constant('pi'),
                'e' => new Constant('e'),
                default => new Variable($text),
            };
        }

        throw new ParseException($type === 'end'
            ? 'The input ends unexpectedly.'
            : sprintf('Unexpected "%s" at position %d.', $text, $position));
    }

    /**
     * @throws ParseException
     */
    private function functionCall(string $name, int $position): Expression
    {
        $this->expect('(');
        $arguments = [$this->expression()];

        while ($this->peek() === ',') {
            $this->next();
            $arguments[] = $this->expression();
        }

        $this->expect(')');

        $name = self::ALIASES[$name] ?? $name;
        $expected = $name === 'log' ? 2 : 1;

        if (count($arguments) !== $expected) {
            throw new ParseException($name === 'log'
                ? 'log needs a base: log(x, base). Use ln(x) for the natural logarithm and log10(x) for base 10.'
                : sprintf('%s takes one argument (at position %d).', $name, $position));
        }

        $x = $arguments[0];

        return match ($name) {
            'sqrt' => Simplifier::power($x, new Num(Number::fraction(1, 2))),
            'cbrt' => Simplifier::power($x, new Num(Number::fraction(1, 3))),
            'log10' => Simplifier::product([Simplifier::call('ln', $x), Simplifier::power(Simplifier::call('ln', new Num(Number::of(10))), new Num(Number::of(-1)))]),
            'log2' => Simplifier::product([Simplifier::call('ln', $x), Simplifier::power(Simplifier::call('ln', new Num(Number::of(2))), new Num(Number::of(-1)))]),
            'log' => Simplifier::product([Simplifier::call('ln', $x), Simplifier::power(Simplifier::call('ln', $arguments[1]), new Num(Number::of(-1)))]),
            default => Simplifier::call($name, $x),
        };
    }

    private function isFunction(string $name): bool
    {
        return in_array(self::ALIASES[$name] ?? $name, [...Expression::FUNCTIONS, ...self::EXTRA_FUNCTIONS], true);
    }

    private function negate(Expression $expression): Expression
    {
        return Simplifier::product([new Num(Number::of(-1)), $expression]);
    }

    /**
     * @throws ParseException
     */
    private function reciprocal(Expression $expression): Expression
    {
        if ($expression instanceof Num && $expression->value->isZero()) {
            throw new ParseException('Division by zero.');
        }

        return Simplifier::power($expression, new Num(Number::of(-1)));
    }

    /**
     * @throws ParseException
     */
    private function enter(): void
    {
        if (++$this->depth > $this->context->maxDepth) {
            throw new ParseException(sprintf('The expression is nested deeper than %d levels.', $this->context->maxDepth));
        }
    }

    private function peek(): string
    {
        return $this->tokens[$this->cursor][0];
    }

    /**
     * @return array{string, string, int}
     */
    private function next(): array
    {
        $token = $this->tokens[$this->cursor];

        if ($token[0] !== 'end') {
            $this->cursor++;
        }

        return $token;
    }

    /**
     * @throws ParseException
     */
    private function expect(string $type): void
    {
        [$actual, $text, $position] = $this->tokens[$this->cursor];

        if ($actual !== $type) {
            throw new ParseException($actual === 'end'
                ? sprintf('The input ends unexpectedly, expected "%s".', $type)
                : sprintf('Unexpected "%s" at position %d%s.', $text, $position, $type === 'end' ? '' : sprintf(', expected "%s"', $type)));
        }

        if ($type !== 'end') {
            $this->cursor++;
        }
    }
}
