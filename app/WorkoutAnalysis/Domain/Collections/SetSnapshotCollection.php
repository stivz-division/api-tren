<?php

namespace App\WorkoutAnalysis\Domain\Collections;

use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, WorkoutSetSnapshot> */
final readonly class SetSnapshotCollection implements Countable, IteratorAggregate
{
    /** @var list<WorkoutSetSnapshot> */
    private array $sets;

    public function __construct(WorkoutSetSnapshot ...$sets)
    {
        $sets = array_values($sets);
        usort($sets, static fn (WorkoutSetSnapshot $left, WorkoutSetSnapshot $right): int => $left->position->value <=> $right->position->value);

        foreach ($sets as $index => $set) {
            if ($set->position->value !== $index + 1) {
                throw new InvalidArgumentException('Позиции подходов должны быть уникальными и непрерывными, начиная с 1.');
            }
        }

        $this->sets = $sets;
    }

    /** @return list<WorkoutSetSnapshot> */
    public function all(): array
    {
        return $this->sets;
    }

    public function count(): int
    {
        return count($this->sets);
    }

    /** @return Traversable<int, WorkoutSetSnapshot> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->sets);
    }
}
