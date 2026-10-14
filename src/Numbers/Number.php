<?php

declare(strict_types=1);

namespace Vortech\Factor\Numbers;

use Stringable;
use Vortech\Factor\Exceptions\DomainException;

/**
 * An exact rational number: an integer numerator over a positive integer denominator, in lowest terms.
 *
 * All arithmetic (+ - * / and integer powers) is exact. Nothing is ever rounded unless `round()`
 * or `toDecimal()` is asked to, so 1/3 + 1/6 is exactly 1/2 and 0.1 + 0.2 is exactly 0.3.
 */
final readonly class Number implements Stringable
{
    /** The largest integer exponent `pow()` accepts, to keep a hostile input from exhausting memory. */
    public const MAX_EXPONENT = 10_000;

    /** The largest decimal exponent a literal such as 1e400 may carry. */
    private const MAX_LITERAL_EXPONENT = 1_000;

    /**
     * @param  numeric-string  $numerator  an integer, with a leading minus when negative
     * @param  numeric-string  $denominator  a positive integer
     */
    private function __construct(
        public string $numerator,
        public string $denominator = '1',
    ) {}

    /**
     * Builds a number from an integer, a decimal string ("0.1", "-12.5e-3"), a fraction ("1/3", "0.5/3")
     * or a float. A float is taken as the shortest decimal that round-trips, so `0.1` is exactly 1/10.
     *
     * @throws DomainException
     */
    public static function of(self|int|string|float $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            is_int($value) => new self((string) $value),
            is_float($value) => self::fromFloat($value),
            default => self::fromString($value),
        };
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public static function one(): self
    {
        return new self('1');
    }

    /**
     * @param  numeric-string|int  $numerator
     * @param  numeric-string|int  $denominator
     *
     * @throws DomainException
     */
    public static function fraction(string|int $numerator, string|int $denominator): self
    {
        return self::fromParts(self::integerString($numerator), self::integerString($denominator));
    }

    /**
     * @throws DomainException
     */
    private static function fromFloat(float $value): self
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new DomainException('NaN and infinity are not numbers.');
        }

        return self::fromString(var_export($value, true));
    }

    /**
     * @throws DomainException
     */
    private static function fromString(string $value): self
    {
        $value = trim($value);

        if (str_contains($value, '/')) {
            [$left, $right] = array_pad(explode('/', $value, 2), 2, '');

            return self::fromString($left)->div(self::fromString($right));
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $value, $m) !== 1
            || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new DomainException(sprintf('"%s" is not a number.', $value));
        }

        $fraction = $m[3] ?? '';
        $exponent = (int) ($m[4] ?? 0);

        if (abs($exponent) > self::MAX_LITERAL_EXPONENT) {
            throw new DomainException('The exponent of the number is too large.');
        }

        $digits = ltrim($m[2].$fraction, '0');
        $numerator = ($digits === '' ? '0' : $digits);
        $scale = strlen($fraction) - $exponent;
        $denominator = '1';

        if ($scale > 0) {
            $denominator = '1'.str_repeat('0', $scale);
        } elseif ($scale < 0) {
            $numerator .= str_repeat('0', -$scale);
        }

        if ($m[1] === '-' && $numerator !== '0') {
            $numerator = '-'.$numerator;
        }

        return self::fromParts($numerator, $denominator);
    }

    /**
     * @throws DomainException
     */
    private static function integerString(string|int $value): string
    {
        $value = (string) $value;

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new DomainException(sprintf('"%s" is not an integer.', $value));
        }

        return $value;
    }

    /**
     * @throws DomainException
     */
    private static function fromParts(string $numerator, string $denominator): self
    {
        if (! is_numeric($numerator) || ! is_numeric($denominator)) {
            throw new DomainException('The numerator and the denominator must be integers.');
        }

        if (bccomp($denominator, '0', 0) === 0) {
            throw new DomainException('Division by zero.');
        }

        if (bccomp($denominator, '0', 0) < 0) {
            $numerator = bcmul($numerator, '-1', 0);
            $denominator = bcmul($denominator, '-1', 0);
        }

        if ($numerator === '0' || bccomp($numerator, '0', 0) === 0) {
            return new self('0');
        }

        if ($denominator !== '1') {
            $gcd = self::gcd(ltrim($numerator, '-'), $denominator);

            if ($gcd !== '1') {
                $numerator = bcdiv($numerator, $gcd, 0);
                $denominator = bcdiv($denominator, $gcd, 0);
            }
        }

        return new self($numerator, $denominator);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    private static function gcd(string $a, string $b): string
    {
        while (bccomp($b, '0', 0) !== 0) {
            [$a, $b] = [$b, bcmod($a, $b, 0)];
        }

        return $a;
    }

    public function add(self|int|string|float $other): self
    {
        $other = self::of($other);

        if ($this->denominator === '1' && $other->denominator === '1') {
            return new self(bcadd($this->numerator, $other->numerator, 0));
        }

        return self::fromParts(
            bcadd(bcmul($this->numerator, $other->denominator, 0), bcmul($other->numerator, $this->denominator, 0), 0),
            bcmul($this->denominator, $other->denominator, 0),
        );
    }

    public function sub(self|int|string|float $other): self
    {
        return $this->add(self::of($other)->negate());
    }

    public function mul(self|int|string|float $other): self
    {
        $other = self::of($other);

        if ($this->denominator === '1' && $other->denominator === '1') {
            return new self(bcmul($this->numerator, $other->numerator, 0));
        }

        return self::fromParts(
            bcmul($this->numerator, $other->numerator, 0),
            bcmul($this->denominator, $other->denominator, 0),
        );
    }

    /**
     * @throws DomainException when dividing by zero
     */
    public function div(self|int|string|float $other): self
    {
        $other = self::of($other);

        if ($other->isZero()) {
            throw new DomainException('Division by zero.');
        }

        return self::fromParts(
            bcmul($this->numerator, $other->denominator, 0),
            bcmul($this->denominator, $other->numerator, 0),
        );
    }

    public function negate(): self
    {
        return $this->isZero() ? $this : new self(bcmul($this->numerator, '-1', 0), $this->denominator);
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    /**
     * @throws DomainException on zero
     */
    public function reciprocal(): self
    {
        return self::one()->div($this);
    }

    /**
     * @throws DomainException when the exponent is out of range, or for 0 raised to a negative power
     */
    public function pow(int $exponent): self
    {
        if (abs($exponent) > self::MAX_EXPONENT) {
            throw new DomainException(sprintf('The exponent must be between -%d and %d.', self::MAX_EXPONENT, self::MAX_EXPONENT));
        }

        if ($exponent < 0) {
            return $this->reciprocal()->pow(-$exponent);
        }

        return new self(
            bcpow($this->numerator, (string) $exponent, 0),
            bcpow($this->denominator, (string) $exponent, 0),
        );
    }

    public function compare(self|int|string|float $other): int
    {
        $other = self::of($other);

        if ($this->denominator === '1' && $other->denominator === '1') {
            return bccomp($this->numerator, $other->numerator, 0);
        }

        return bccomp(
            bcmul($this->numerator, $other->denominator, 0),
            bcmul($other->numerator, $this->denominator, 0),
            0,
        );
    }

    public function equals(self|int|string|float $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function lessThan(self|int|string|float $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function lessThanOrEqual(self|int|string|float $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function greaterThan(self|int|string|float $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function greaterThanOrEqual(self|int|string|float $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function isZero(): bool
    {
        return $this->numerator === '0';
    }

    public function isNegative(): bool
    {
        return $this->numerator[0] === '-';
    }

    public function isPositive(): bool
    {
        return ! $this->isZero() && ! $this->isNegative();
    }

    public function isInteger(): bool
    {
        return $this->denominator === '1';
    }

    /**
     * -1, 0 or 1.
     */
    public function sign(): int
    {
        return $this->isZero() ? 0 : ($this->isNegative() ? -1 : 1);
    }

    public function min(self|int|string|float $other): self
    {
        $other = self::of($other);

        return $this->compare($other) <= 0 ? $this : $other;
    }

    public function max(self|int|string|float $other): self
    {
        $other = self::of($other);

        return $this->compare($other) >= 0 ? $this : $other;
    }

    /**
     * The integer value, or null when the number is not an integer or does not fit in a PHP int.
     */
    public function toInt(): ?int
    {
        if (! $this->isInteger() || bccomp($this->numerator, (string) PHP_INT_MAX, 0) > 0 || bccomp($this->numerator, (string) PHP_INT_MIN, 0) < 0) {
            return null;
        }

        return (int) $this->numerator;
    }

    /**
     * The floor: the largest integer not above the number.
     */
    public function floor(): self
    {
        return new self($this->integerPart(RoundingMode::Floor));
    }

    public function ceil(): self
    {
        return new self($this->integerPart(RoundingMode::Ceiling));
    }

    /**
     * Rounds to the given number of decimal places, exactly (the result is again an exact number).
     *
     * @param  int  $scale  decimal places; a negative scale rounds to tens, hundreds, ...
     */
    public function round(int $scale = 0, RoundingMode $mode = RoundingMode::HalfAwayFromZero): self
    {
        $factor = new self(bcpow('10', (string) abs($scale), 0));

        if ($scale >= 0) {
            return self::fromParts($this->mul($factor)->integerPart($mode), $factor->numerator);
        }

        return (new self($this->div($factor)->integerPart($mode)))->mul($factor);
    }

    /**
     * The number as a decimal string with exactly `$scale` decimal places, rounded with `$mode`.
     *
     * @param  int<0, max>  $scale
     */
    public function toDecimal(int $scale = 2, RoundingMode $mode = RoundingMode::HalfAwayFromZero): string
    {
        $integer = $this->mul(new self(bcpow('10', (string) $scale, 0)))->integerPart($mode);

        if ($scale === 0) {
            return $integer;
        }

        $negative = $integer[0] === '-';
        $digits = str_pad(ltrim($integer, '-'), $scale + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    /**
     * Splits the number into parts proportional to the ratios, in units of 10^-$scale, so that the parts add up to
     * the number exactly (the largest remainder method). Money cannot be split into thirds: 100.00 over three equal
     * parts gives 33.34, 33.33, 33.33, never 99.99 or 100.01. Ties go to the earlier part.
     *
     * @param  non-empty-array<int, Number|int|float|string>  $ratios  non-negative, at least one above zero
     * @param  int<0, max>  $scale  decimal places of the parts
     * @return list<Number>
     *
     * @throws DomainException for a negative or all-zero ratio, or when the number has more than `$scale` decimal places
     */
    public function allocate(array $ratios, int $scale = 2): array
    {
        $weights = array_values(array_map(self::of(...), $ratios));
        $total = self::zero();

        foreach ($weights as $weight) {
            if ($weight->isNegative()) {
                throw new DomainException('The ratios must not be negative.');
            }

            $total = $total->add($weight);
        }

        if ($total->isZero()) {
            throw new DomainException('At least one ratio must be above zero.');
        }

        $unit = self::of(10)->pow($scale);
        $units = $this->mul($unit);

        if (! $units->isInteger()) {
            throw new DomainException(sprintf('%s has more than %d decimal places; round it first, so that no money is lost silently.', $this, $scale));
        }

        $negative = $units->isNegative();
        $units = $units->abs();

        $floors = [];
        $remainders = [];
        $assigned = self::zero();

        foreach ($weights as $index => $weight) {
            $exact = $units->mul($weight)->div($total);
            $floors[$index] = $exact->floor();
            $remainders[$index] = $exact->sub($floors[$index]);
            $assigned = $assigned->add($floors[$index]);
        }

        $leftover = (int) $units->sub($assigned)->numerator;
        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => [$remainders[$b]->compare($remainders[$a]), $a] <=> [0, $b]);

        foreach (array_slice($order, 0, $leftover) as $index) {
            $floors[$index] = $floors[$index]->add(1);
        }

        $parts = array_map(static fn (self $part): self => $part->div($unit), $floors);

        return array_values($negative ? array_map(static fn (self $part): self => $part->negate(), $parts) : $parts);
    }

    /**
     * Splits the number into equal parts, in units of 10^-$scale, adding up to the number exactly.
     *
     * @param  int<0, max>  $scale
     * @return list<Number>
     */
    public function split(int $parts, int $scale = 2): array
    {
        if ($parts < 1) {
            throw new DomainException('A number can only be split into one or more parts.');
        }

        return $this->allocate(array_fill(0, $parts, 1), $scale);
    }

    /**
     * The nearest float. Loses the exactness, use it only to hand a result to code that wants a float.
     */
    public function toFloat(): float
    {
        return (float) bcdiv($this->numerator, $this->denominator, 40);
    }

    /**
     * Whether the decimal expansion ends (the denominator has no prime factor but 2 and 5).
     */
    public function isTerminating(): bool
    {
        $denominator = $this->denominator;

        foreach (['2', '5'] as $prime) {
            while (bccomp(bcmod($denominator, $prime, 0), '0', 0) === 0) {
                $denominator = bcdiv($denominator, $prime, 0);
            }
        }

        return $denominator === '1';
    }

    /**
     * The exact square root when the number is the square of a rational, otherwise null.
     */
    public function exactSqrt(): ?self
    {
        if ($this->isNegative()) {
            return null;
        }

        $numerator = bcsqrt($this->numerator, 0);
        $denominator = bcsqrt($this->denominator, 0);

        if (bccomp(bcmul($numerator, $numerator, 0), $this->numerator, 0) !== 0
            || bccomp(bcmul($denominator, $denominator, 0), $this->denominator, 0) !== 0) {
            return null;
        }

        return self::fromParts($numerator, $denominator);
    }

    /**
     * The decimal digits before the point of a terminating number, otherwise the fraction: "0.125" or "1/3".
     */
    public function __toString(): string
    {
        if ($this->isInteger()) {
            return $this->numerator;
        }

        if ($this->isTerminating()) {
            $places = 1;

            while (! $this->mul(new self(bcpow('10', (string) $places, 0)))->isInteger()) {
                $places++;
            }

            return $this->toDecimal($places);
        }

        return $this->numerator.'/'.$this->denominator;
    }

    /**
     * The integer the number rounds to.
     *
     * @return numeric-string
     */
    private function integerPart(RoundingMode $mode): string
    {
        if ($this->denominator === '1') {
            return $this->numerator;
        }

        $negative = $this->isNegative();
        $quotient = bcdiv($this->numerator, $this->denominator, 0); // truncates towards zero
        $remainder = bcmod($this->numerator, $this->denominator, 0);
        $step = $negative ? '-1' : '1';

        $away = match ($mode) {
            RoundingMode::Truncate => false,
            RoundingMode::AwayFromZero => true,
            RoundingMode::Floor => $negative,
            RoundingMode::Ceiling => ! $negative,
            RoundingMode::HalfAwayFromZero, RoundingMode::HalfEven => $this->halfDecision($mode, $remainder, $quotient),
        };

        return $away ? bcadd($quotient, $step, 0) : $quotient;
    }

    /**
     * @param  numeric-string  $remainder
     * @param  numeric-string  $quotient
     */
    private function halfDecision(RoundingMode $mode, string $remainder, string $quotient): bool
    {
        $twice = bcmul(ltrim($remainder, '-'), '2', 0);
        $comparison = bccomp($twice, $this->denominator, 0);

        if ($comparison !== 0) {
            return $comparison > 0;
        }

        return $mode === RoundingMode::HalfAwayFromZero || bccomp(bcmod($quotient, '2', 0), '0', 0) !== 0;
    }
}
