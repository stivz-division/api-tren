<?php

namespace App\WorkoutExecution\Domain\Collections;

use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\Exceptions\ExerciseAlreadyAddedToWorkout;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseOrder;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseNotFound;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, WorkoutExercise> */
final class WorkoutExerciseCollection implements Countable, IteratorAggregate
{
    /** @var array<int, WorkoutExercise> */
    private array $exercises = [];

    public function __construct(WorkoutExercise $firstExercise, WorkoutExercise ...$remainingExercises)
    {
        $this->store($firstExercise);

        foreach ($remainingExercises as $exercise) {
            $this->store($exercise);
        }

        $this->assertContiguousPositions();
    }

    public function get(ExerciseId $exerciseId): WorkoutExercise
    {
        return $this->exercises[$exerciseId->value]
            ?? throw new WorkoutExerciseNotFound($exerciseId);
    }

    /** @return non-empty-list<WorkoutExercise> */
    public function all(): array
    {
        $exercises = array_values($this->exercises);

        usort(
            $exercises,
            static fn (WorkoutExercise $left, WorkoutExercise $right): int => $left->snapshot->position->value <=> $right->snapshot->position->value,
        );

        /** @var non-empty-list<WorkoutExercise> $exercises */
        return $exercises;
    }

    public function copy(): self
    {
        $exercises = array_map(
            static fn (WorkoutExercise $exercise): WorkoutExercise => clone $exercise,
            $this->all(),
        );

        return new self($exercises[0], ...array_slice($exercises, 1));
    }

    public function freshFromPlan(): self
    {
        $exercises = array_map(
            static fn (WorkoutExercise $exercise): WorkoutExercise => WorkoutExercise::fromPlan(
                $exercise->snapshot,
                $exercise->plannedPrescription,
            ),
            $this->all(),
        );

        return new self($exercises[0], ...array_slice($exercises, 1));
    }

    public function allResolved(): bool
    {
        foreach ($this->exercises as $exercise) {
            if ($exercise->status === WorkoutExerciseStatus::Pending) {
                return false;
            }
        }

        return true;
    }

    public function count(): int
    {
        return count($this->exercises);
    }

    /** @return Traversable<int, WorkoutExercise> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    private function store(WorkoutExercise $exercise): void
    {
        $exerciseId = $exercise->snapshot->exerciseId;

        if (isset($this->exercises[$exerciseId->value])) {
            throw new ExerciseAlreadyAddedToWorkout($exerciseId);
        }

        $this->exercises[$exerciseId->value] = $exercise;
    }

    private function assertContiguousPositions(): void
    {
        $positions = array_map(
            static fn (WorkoutExercise $exercise): int => $exercise->snapshot->position->value,
            $this->exercises,
        );

        sort($positions);

        if ($positions !== range(1, $this->count())) {
            throw new InvalidWorkoutExerciseOrder;
        }
    }
}
