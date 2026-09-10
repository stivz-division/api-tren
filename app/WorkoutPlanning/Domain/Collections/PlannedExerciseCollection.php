<?php

namespace App\WorkoutPlanning\Domain\Collections;

use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Exceptions\ExerciseAlreadyPlanned;
use App\WorkoutPlanning\Domain\Exceptions\InvalidExerciseOrder;
use App\WorkoutPlanning\Domain\Exceptions\PlannedExerciseNotFound;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, PlannedExercise> */
final class PlannedExerciseCollection implements Countable, IteratorAggregate
{
    /** @var array<int, PlannedExercise> */
    private array $exercises = [];

    public function __construct(PlannedExercise $firstExercise, PlannedExercise ...$remainingExercises)
    {
        $this->store($firstExercise);

        foreach ($remainingExercises as $exercise) {
            $this->store($exercise);
        }

        $this->assertContiguousPositions();
    }

    public function add(PlannedExercise $exercise): void
    {
        if ($this->contains($exercise->exerciseId)) {
            throw new ExerciseAlreadyPlanned($exercise->exerciseId);
        }

        if ($exercise->position->value !== $this->count() + 1) {
            throw new InvalidExerciseOrder;
        }

        $this->exercises[$exercise->exerciseId->value] = $exercise;
    }

    public function remove(ExerciseId $exerciseId): void
    {
        if (! $this->contains($exerciseId)) {
            throw new PlannedExerciseNotFound($exerciseId);
        }

        if ($this->count() === 1) {
            throw new TrainingProgramMustContainExercise;
        }

        unset($this->exercises[$exerciseId->value]);

        $this->normalizePositions();
    }

    public function contains(ExerciseId $exerciseId): bool
    {
        return isset($this->exercises[$exerciseId->value]);
    }

    public function get(ExerciseId $exerciseId): PlannedExercise
    {
        return $this->exercises[$exerciseId->value]
            ?? throw new PlannedExerciseNotFound($exerciseId);
    }

    public function nextPosition(): ExercisePosition
    {
        return new ExercisePosition($this->count() + 1);
    }

    public function copy(): self
    {
        /** @var non-empty-list<PlannedExercise> $exercises */
        $exercises = array_map(
            static fn (PlannedExercise $exercise): PlannedExercise => clone $exercise,
            $this->all(),
        );

        return new self($exercises[0], ...array_slice($exercises, 1));
    }

    public function reorder(ExerciseId ...$exerciseIds): void
    {
        $orderedIds = array_map(
            static fn (ExerciseId $exerciseId): int => $exerciseId->value,
            $exerciseIds,
        );
        $currentIds = array_keys($this->exercises);

        sort($orderedIds);
        sort($currentIds);

        if ($orderedIds !== $currentIds || count(array_unique($orderedIds)) !== $this->count()) {
            throw new InvalidExerciseOrder;
        }

        $position = 1;

        foreach ($exerciseIds as $exerciseId) {
            $this->get($exerciseId)->moveTo(new ExercisePosition($position));
            $position++;
        }
    }

    /** @return list<PlannedExercise> */
    public function all(): array
    {
        $exercises = array_values($this->exercises);

        usort(
            $exercises,
            static fn (PlannedExercise $left, PlannedExercise $right): int => $left->position->value <=> $right->position->value,
        );

        return $exercises;
    }

    public function count(): int
    {
        return count($this->exercises);
    }

    /** @return Traversable<int, PlannedExercise> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    private function store(PlannedExercise $exercise): void
    {
        if ($this->contains($exercise->exerciseId)) {
            throw new ExerciseAlreadyPlanned($exercise->exerciseId);
        }

        $this->exercises[$exercise->exerciseId->value] = $exercise;
    }

    private function assertContiguousPositions(): void
    {
        $positions = array_map(
            static fn (PlannedExercise $exercise): int => $exercise->position->value,
            $this->exercises,
        );

        sort($positions);

        if ($positions !== range(1, $this->count())) {
            throw new InvalidExerciseOrder;
        }
    }

    private function normalizePositions(): void
    {
        foreach ($this->all() as $index => $exercise) {
            $exercise->moveTo(new ExercisePosition($index + 1));
        }
    }
}
