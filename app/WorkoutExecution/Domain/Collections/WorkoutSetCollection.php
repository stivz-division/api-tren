<?php

namespace App\WorkoutExecution\Domain\Collections;

use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutSetOrder;
use App\WorkoutExecution\Domain\ValueObjects\PlannedPrescription;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, WorkoutSet> */
final class WorkoutSetCollection implements Countable, IteratorAggregate
{
    /** @var array<int, WorkoutSet> */
    private array $sets = [];

    public function __construct(WorkoutSet ...$sets)
    {
        foreach ($sets as $set) {
            if (isset($this->sets[$set->position->value])) {
                throw new InvalidWorkoutSetOrder;
            }

            $this->sets[$set->position->value] = $set;
        }

        $this->assertContiguousPositions();
    }

    public static function fromPrescription(PlannedPrescription $prescription): self
    {
        $sets = [];

        for ($position = 1; $position <= $prescription->setsCount->value; $position++) {
            $sets[] = new WorkoutSet(
                new SetPosition($position),
                $prescription->repetitionsPerSet,
                $prescription->workingWeight,
            );
        }

        return new self(...$sets);
    }

    /** @return list<WorkoutSet> */
    public function all(): array
    {
        $sets = array_values($this->sets);

        usort(
            $sets,
            static fn (WorkoutSet $left, WorkoutSet $right): int => $left->position->value <=> $right->position->value,
        );

        return $sets;
    }

    public function copy(): self
    {
        return new self(...$this->all());
    }

    public function isEmpty(): bool
    {
        return $this->sets === [];
    }

    public function equals(self $other): bool
    {
        $sets = $this->all();
        $otherSets = $other->all();

        if (count($sets) !== count($otherSets)) {
            return false;
        }

        foreach ($sets as $index => $set) {
            $otherSet = $otherSets[$index];

            if (
                $set->position->value !== $otherSet->position->value
                || $set->repetitions->value !== $otherSet->repetitions->value
                || $set->workingWeight->grams !== $otherSet->workingWeight->grams
            ) {
                return false;
            }
        }

        return true;
    }

    public function count(): int
    {
        return count($this->sets);
    }

    /** @return Traversable<int, WorkoutSet> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    private function assertContiguousPositions(): void
    {
        if ($this->sets === []) {
            return;
        }

        $positions = array_keys($this->sets);
        sort($positions);

        if ($positions !== range(1, $this->count())) {
            throw new InvalidWorkoutSetOrder;
        }
    }
}
