<?php

declare(strict_types=1);

namespace Vortech\Factor\Expressions;

use Vortech\Factor\Numbers\Number;

/**
 * Writes expressions as text that parses back to the same expression, or as LaTeX.
 *
 * @internal Use the Factor entry point or the Expression methods; this class may change in a minor release.
 */
final readonly class Printer
{
    private const SUM = 1;

    private const PRODUCT = 2;

    private const POWER = 4;

    private const ATOM = 5;

    private const LATEX_FUNCTIONS = [
        'asin' => '\arcsin', 'acos' => '\arccos', 'atan' => '\arctan',
    ];

    private function __construct(private bool $latex) {}

    public static function string(Expression $expression): string
    {
        return (new self(false))->print($expression, 0);
    }

    public static function latex(Expression $expression): string
    {
        return (new self(true))->print($expression, 0);
    }

    private function print(Expression $expression, int $parent): string
    {
        $text = $this->render($expression);

        if ($this->precedence($expression) >= $parent) {
            return $text;
        }

        return $this->latex ? '\left('.$text.'\right)' : '('.$text.')';
    }

    private function precedence(Expression $expression): int
    {
        return match (true) {
            $expression instanceof Num => $expression->value->isNegative()
                ? self::SUM
                : (! $this->latex && ! $expression->value->isInteger() && ! $expression->value->isTerminating() ? self::PRODUCT : self::ATOM),
            $expression instanceof Sum => self::SUM,
            $expression instanceof Product => self::PRODUCT,
            $expression instanceof Power => $this->isSquareRoot($expression) ? self::ATOM : ($this->isReciprocal($expression) ? self::PRODUCT : self::POWER),
            default => self::ATOM,
        };
    }

    private function render(Expression $expression): string
    {
        return match (true) {
            $expression instanceof Num => $this->number($expression->value),
            $expression instanceof Variable => $expression->name,
            $expression instanceof Constant => $this->latex && $expression->name === 'pi' ? '\pi' : $expression->name,
            $expression instanceof Sum => $this->sum($expression),
            $expression instanceof Product => $this->product($expression),
            $expression instanceof Power => $this->power($expression),
            $expression instanceof Call => $this->call($expression),
            default => $expression->key(),
        };
    }

    private function number(Number $number): string
    {
        if ($this->latex && ! $number->isInteger() && ! $number->isTerminating()) {
            return ($number->isNegative() ? '-' : '').'\frac{'.ltrim($number->numerator, '-').'}{'.$number->denominator.'}';
        }

        return (string) $number;
    }

    private function sum(Sum $sum): string
    {
        $text = $this->print($sum->terms[0], self::SUM);

        foreach (array_slice($sum->terms, 1) as $term) {
            if (Simplifier::isNegativeLeading($term)) {
                $text .= ' - '.$this->print(Simplifier::product([new Num(Number::of(-1)), $term]), self::SUM);
            } else {
                $text .= ' + '.$this->print($term, self::SUM);
            }
        }

        return $text;
    }

    private function product(Product $product): string
    {
        $coefficient = Number::one();
        $numerator = [];
        $denominator = [];

        foreach ($product->factors as $factor) {
            if ($factor instanceof Num) {
                $coefficient = $factor->value;
            } elseif ($this->isReciprocal($factor)) {
                /** @var Power $factor */
                $denominator[] = $this->print(Simplifier::power($factor->base, new Num($this->exponentValue($factor)->negate())), $this->latex ? 0 : self::PRODUCT + 1);
            } else {
                $numerator[] = $this->print($factor, self::PRODUCT + 1);
            }
        }

        $sign = $coefficient->isNegative() ? '-' : '';
        $magnitude = $coefficient->abs();

        if (! $magnitude->isInteger() && ! $magnitude->isTerminating()) {
            array_unshift($denominator, $this->plain(Number::of($magnitude->denominator)));
            $magnitude = Number::of($magnitude->numerator);
        }

        if (! $magnitude->equals(1) || $numerator === []) {
            array_unshift($numerator, $this->plain($magnitude));
        }

        if ($this->latex) {
            $top = $this->joinLatex($numerator);

            return $sign.($denominator === [] ? $top : '\frac{'.$top.'}{'.$this->joinLatex($denominator).'}');
        }

        $text = $sign.implode('*', $numerator);

        if ($denominator !== []) {
            $text .= '/'.(count($denominator) > 1 ? '('.implode('*', $denominator).')' : $denominator[0]);
        }

        return $text;
    }

    private function power(Power $power): string
    {
        if ($this->isSquareRoot($power)) {
            $inner = $this->print($power->base, 0);

            return $this->latex ? '\sqrt{'.$inner.'}' : 'sqrt('.$inner.')';
        }

        if ($this->isReciprocal($power)) {
            $positive = Simplifier::power($power->base, new Num($this->exponentValue($power)->negate()));

            return $this->latex
                ? '\frac{1}{'.$this->print($positive, 0).'}'
                : '1/'.$this->print($positive, self::PRODUCT + 1);
        }

        $base = $this->print($power->base, self::POWER + 1);

        if ($this->latex) {
            return $base.'^{'.$this->print($power->exponent, 0).'}';
        }

        return $base.'^'.$this->print($power->exponent, self::POWER + 1);
    }

    private function call(Call $call): string
    {
        $argument = $this->print($call->argument, 0);

        if (! $this->latex) {
            return $call->name.'('.$argument.')';
        }

        return (self::LATEX_FUNCTIONS[$call->name] ?? '\\'.$call->name).'\left('.$argument.'\right)';
    }

    /**
     * @param  list<string>  $factors
     */
    private function joinLatex(array $factors): string
    {
        $text = array_shift($factors) ?? '1';

        foreach ($factors as $factor) {
            $text .= (ctype_digit($factor[0]) ? ' \cdot ' : ' ').$factor;
        }

        return $text;
    }

    private function plain(Number $number): string
    {
        return $this->latex ? $this->number($number) : (string) $number;
    }

    private function isSquareRoot(Expression $expression): bool
    {
        return $expression instanceof Power
            && $expression->exponent instanceof Num
            && $expression->exponent->value->equals('1/2');
    }

    private function isReciprocal(Expression $expression): bool
    {
        return $expression instanceof Power
            && $expression->exponent instanceof Num
            && $expression->exponent->value->isNegative();
    }

    private function exponentValue(Power $power): Number
    {
        return $power->exponent instanceof Num ? $power->exponent->value : Number::one();
    }
}
