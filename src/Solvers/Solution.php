<?php

declare(strict_types=1);

namespace Vortech\Factor\Solvers;

use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use OutOfRangeException;
use Traversable;
use Vortech\Factor\Numbers\Number;

/**
 * The solutions of an equation: each distinct root once, real roots before complex ones in ascending order,
 * or the statement that every number is a solution.
 *
 * @implements IteratorAggregate<int, Root>
 */
final readonly class Solution implements Countable, IteratorAggregate
{
    /**
     * @param  list<Root>  $roots
     */
    public function __construct(private array $roots, private bool $all = false) {}

    /**
     * Every root, real and complex.
     *
     * @return Collection<int, Root>
     */
    public function roots(): Collection
    {
        return new Collection($this->roots);
    }

    /**
     * The root at a position, counting from 1: `nth(1)` is the smallest real root.
     *
     * @param  positive-int  $position
     *
     * @throws OutOfRangeException when there is no such root
     */
    public function nth(int $position): Root
    {
        return $this->roots[$position - 1]
            ?? throw new OutOfRangeException(sprintf('There is no root number %d, the equation has %d.', $position, count($this->roots)));
    }

    /**
     * Whether every number solves the equation (it reduced to 0 = 0).
     */
    public function isAll(): bool
    {
        return $this->all;
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->roots === [];
    }

    /**
     * The real roots.
     *
     * @return Collection<int, Root>
     */
    public function real(): Collection
    {
        return $this->roots()->filter(static fn (Root $root): bool => $root->isReal())->values();
    }

    /**
     * The values of the real roots.
     *
     * @return Collection<int, Number>
     */
    public function values(): Collection
    {
        return $this->real()->map(static fn (Root $root): Number => $root->real);
    }

    public function count(): int
    {
        return count($this->roots);
    }

    /**
     * @return Traversable<int, Root>
     */
    public function getIterator(): Traversable
    {
        return $this->roots()->getIterator();
    }
}
