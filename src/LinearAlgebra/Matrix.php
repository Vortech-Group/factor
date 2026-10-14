<?php

declare(strict_types=1);

namespace Vortech\Factor\LinearAlgebra;

use Vortech\Factor\Exceptions\DomainException;
use Vortech\Factor\Exceptions\SingularMatrixException;
use Vortech\Factor\Numbers\Number;

/**
 * An immutable matrix of exact rational numbers. Elimination is done in exact arithmetic, so there is no
 * rounding error and no need for numerical pivoting: a matrix is singular exactly when it is singular.
 */
final readonly class Matrix
{
    /**
     * @param  list<list<Number>>  $rows
     */
    private function __construct(private array $rows, public int $height, public int $width) {}

    /**
     * @param  array<array<Number|int|float|string>>  $rows
     *
     * @throws DomainException when the rows are empty or of different lengths
     */
    public static function of(array $rows): self
    {
        $rows = array_values(array_map(
            static fn (array $row): array => array_values(array_map(Number::of(...), $row)),
            $rows,
        ));

        $width = count($rows[0] ?? []);

        if ($rows === [] || $width === 0) {
            throw new DomainException('A matrix needs at least one row and one column.');
        }

        foreach ($rows as $row) {
            if (count($row) !== $width) {
                throw new DomainException('All rows of a matrix must have the same length.');
            }
        }

        return new self($rows, count($rows), $width);
    }

    /**
     * @throws DomainException for a size below 1
     */
    public static function identity(int $size): self
    {
        if ($size < 1) {
            throw new DomainException('A matrix needs at least one row and one column.');
        }

        $rows = [];

        for ($i = 0; $i < $size; $i++) {
            $rows[] = array_map(static fn (int $j): Number => $i === $j ? Number::one() : Number::zero(), range(0, $size - 1));
        }

        return new self($rows, $size, $size);
    }

    public function get(int $row, int $column): Number
    {
        return $this->rows[$row][$column] ?? throw new DomainException('The matrix has no such element.');
    }

    /**
     * @return list<list<Number>>
     */
    public function toArray(): array
    {
        return $this->rows;
    }

    public function isSquare(): bool
    {
        return $this->height === $this->width;
    }

    public function transpose(): self
    {
        $rows = [];

        for ($j = 0; $j < $this->width; $j++) {
            $rows[] = array_column($this->rows, $j);
        }

        return new self($rows, $this->width, $this->height);
    }

    public function add(self $other): self
    {
        $this->assertSameShape($other);

        return $this->map(static fn (Number $value, int $i, int $j): Number => $value->add($other->rows[$i][$j]));
    }

    public function sub(self $other): self
    {
        $this->assertSameShape($other);

        return $this->map(static fn (Number $value, int $i, int $j): Number => $value->sub($other->rows[$i][$j]));
    }

    public function scale(Number|int|float|string $factor): self
    {
        $factor = Number::of($factor);

        return $this->map(static fn (Number $value): Number => $value->mul($factor));
    }

    public function multiply(self $other): self
    {
        if ($this->width !== $other->height) {
            throw new DomainException(sprintf('A %dx%d matrix cannot be multiplied by a %dx%d matrix.', $this->height, $this->width, $other->height, $other->width));
        }

        $rows = [];

        for ($i = 0; $i < $this->height; $i++) {
            $row = [];

            for ($j = 0; $j < $other->width; $j++) {
                $sum = Number::zero();

                for ($k = 0; $k < $this->width; $k++) {
                    $sum = $sum->add($this->rows[$i][$k]->mul($other->rows[$k][$j]));
                }

                $row[] = $sum;
            }

            $rows[] = $row;
        }

        return new self($rows, $this->height, $other->width);
    }

    /**
     * @throws DomainException for a matrix that is not square
     */
    public function determinant(): Number
    {
        $this->assertSquare();

        $rows = $this->rows;
        $determinant = Number::one();

        for ($column = 0; $column < $this->width; $column++) {
            $pivot = $this->findPivot($rows, $column, $column);

            if ($pivot === null) {
                return Number::zero();
            }

            if ($pivot !== $column) {
                [$rows[$pivot], $rows[$column]] = [$rows[$column], $rows[$pivot]];
                $determinant = $determinant->negate();
            }

            $determinant = $determinant->mul($rows[$column][$column]);

            for ($i = $column + 1; $i < $this->height; $i++) {
                $factor = $rows[$i][$column]->div($rows[$column][$column]);

                for ($j = $column; $j < $this->width; $j++) {
                    $rows[$i][$j] = $rows[$i][$j]->sub($factor->mul($rows[$column][$j]));
                }
            }
        }

        return $determinant;
    }

    public function rank(): int
    {
        $rows = $this->rows;
        $rank = 0;

        for ($column = 0; $column < $this->width && $rank < $this->height; $column++) {
            $pivot = $this->findPivot($rows, $column, $rank);

            if ($pivot === null) {
                continue;
            }

            [$rows[$pivot], $rows[$rank]] = [$rows[$rank], $rows[$pivot]];

            for ($i = $rank + 1; $i < $this->height; $i++) {
                $factor = $rows[$i][$column]->div($rows[$rank][$column]);

                for ($j = $column; $j < $this->width; $j++) {
                    $rows[$i][$j] = $rows[$i][$j]->sub($factor->mul($rows[$rank][$j]));
                }
            }

            $rank++;
        }

        return $rank;
    }

    /**
     * @throws SingularMatrixException
     */
    public function inverse(): self
    {
        $this->assertSquare();

        return $this->solveMatrix(self::identity($this->height));
    }

    /**
     * The solution of A x = b for a column vector b, as a list.
     *
     * @param  list<Number|int|float|string>  $b
     * @return list<Number>
     *
     * @throws SingularMatrixException when the matrix is singular, so there is no unique solution
     */
    public function solve(array $b): array
    {
        $this->assertSquare();

        if (count($b) !== $this->height) {
            throw new DomainException('The right-hand side must have one value per row.');
        }

        $solution = $this->solveMatrix(self::of(array_map(static fn (Number|int|float|string $value): array => [$value], $b)));

        return array_column($solution->rows, 0);
    }

    /**
     * Gauss-Jordan elimination on [A | B].
     *
     * @throws SingularMatrixException
     */
    private function solveMatrix(self $right): self
    {
        $rows = [];

        foreach ($this->rows as $i => $row) {
            $rows[] = [...$row, ...$right->rows[$i]];
        }

        $columns = $this->width + $right->width;

        for ($column = 0; $column < $this->width; $column++) {
            $pivot = $this->findPivot($rows, $column, $column);

            if ($pivot === null) {
                throw new SingularMatrixException('The matrix is singular.');
            }

            [$rows[$pivot], $rows[$column]] = [$rows[$column], $rows[$pivot]];
            $divisor = $rows[$column][$column];

            for ($j = 0; $j < $columns; $j++) {
                $rows[$column][$j] = $rows[$column][$j]->div($divisor);
            }

            for ($i = 0; $i < $this->height; $i++) {
                if ($i === $column || $rows[$i][$column]->isZero()) {
                    continue;
                }

                $factor = $rows[$i][$column];

                for ($j = 0; $j < $columns; $j++) {
                    $rows[$i][$j] = $rows[$i][$j]->sub($factor->mul($rows[$column][$j]));
                }
            }
        }

        return new self(array_map(fn (array $row): array => array_slice($row, $this->width), $rows), $this->height, $right->width);
    }

    /**
     * @param  list<list<Number>>  $rows
     */
    private function findPivot(array $rows, int $column, int $from): ?int
    {
        for ($i = $from; $i < count($rows); $i++) {
            if (! $rows[$i][$column]->isZero()) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  callable(Number, int, int): Number  $callback
     */
    private function map(callable $callback): self
    {
        $rows = [];

        foreach ($this->rows as $i => $row) {
            $rows[] = array_map(static fn (Number $value, int $j): Number => $callback($value, $i, $j), $row, array_keys($row));
        }

        return new self($rows, $this->height, $this->width);
    }

    private function assertSquare(): void
    {
        if (! $this->isSquare()) {
            throw new DomainException('The matrix must be square.');
        }
    }

    private function assertSameShape(self $other): void
    {
        if ($this->height !== $other->height || $this->width !== $other->width) {
            throw new DomainException('The matrices must have the same size.');
        }
    }
}
