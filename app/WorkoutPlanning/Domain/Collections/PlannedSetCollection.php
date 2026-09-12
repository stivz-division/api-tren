<?php

namespace App\WorkoutPlanning\Domain\Collections;

use App\WorkoutPlanning\Domain\Exceptions\InvalidPlannedSetOrder;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, PlannedSet> */
final class PlannedSetCollection implements Countable, IteratorAggregate
{
    /** @var array<int, PlannedSet> */
    private array $sets = [];

    public function __construct(PlannedSet $firstSet, PlannedSet ...$remainingSets)
    {
        $this->store($firstSet);

        foreach ($remainingSets as $set) {
            $this->store($set);
        }

        $this->assertContiguousPositions();
    }

    /** @return list<PlannedSet> */
    public function all(): array
    {
        $sets = array_values($this->sets);

        usort(
            $sets,
            static fn (PlannedSet $left, PlannedSet $right): int => $left->position->value <=> $right->position->value,
        );

        return $sets;
    }

    public function copy(): self
    {
        $sets = $this->all();

        return new self($sets[0], ...array_slice($sets, 1));
    }

    public function count(): int
    {
        return count($this->sets);
    }

    /** @return Traversable<int, PlannedSet> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    private function store(PlannedSet $set): void
    {
        if (isset($this->sets[$set->position->value])) {
            throw new InvalidPlannedSetOrder;
        }

        $this->sets[$set->position->value] = $set;
    }

    private function assertContiguousPositions(): void
    {
        $positions = array_keys($this->sets);
        sort($positions);

        if ($positions !== range(1, $this->count())) {
            throw new InvalidPlannedSetOrder;
        }
    }
}
