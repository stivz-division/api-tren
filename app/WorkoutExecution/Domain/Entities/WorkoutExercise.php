<?php

namespace App\WorkoutExecution\Domain\Entities;

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseState;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseHasNoSets;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseIsNotEditable;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;

final class WorkoutExercise
{
    private WorkoutSetCollection $sets;

    private WorkoutSetCollection $plannedSets;

    private function __construct(
        public private(set) readonly ExerciseSnapshot $snapshot,
        WorkoutSetCollection $plannedSets,
        WorkoutSetCollection $sets,
        public private(set) WorkoutExerciseStatus $status,
    ) {
        $this->plannedSets = $plannedSets->copy();
        $this->sets = $sets->copy();
    }

    public static function fromPlan(
        ExerciseSnapshot $snapshot,
        WorkoutSetCollection $plannedSets,
    ): self {
        return new self(
            $snapshot,
            $plannedSets,
            $plannedSets,
            WorkoutExerciseStatus::Pending,
        );
    }

    public static function restore(
        ExerciseSnapshot $snapshot,
        WorkoutSetCollection $plannedSets,
        WorkoutSetCollection $sets,
        WorkoutExerciseStatus $status,
    ): self {
        if ($status === WorkoutExerciseStatus::Completed && $sets->isEmpty()) {
            throw new WorkoutExerciseHasNoSets($snapshot->exerciseId);
        }

        if ($status === WorkoutExerciseStatus::Skipped && ! $sets->isEmpty()) {
            throw new InvalidWorkoutExerciseState;
        }

        return new self($snapshot, $plannedSets, $sets, $status);
    }

    /** @return list<WorkoutSet> */
    public function plannedSets(): array
    {
        return $this->plannedSets->copy()->all();
    }

    /** @return list<WorkoutSet> */
    public function workoutSets(): array
    {
        return $this->sets->copy()->all();
    }

    public function saveProgress(WorkoutSetCollection $sets): void
    {
        $this->assertEditable();

        $this->sets = $sets->copy();
    }

    public function complete(WorkoutSetCollection $sets): void
    {
        if (
            $this->status === WorkoutExerciseStatus::Completed
            && $this->sets->equals($sets)
        ) {
            return;
        }

        $this->assertEditable();

        if ($sets->isEmpty()) {
            throw new WorkoutExerciseHasNoSets($this->snapshot->exerciseId);
        }

        $this->sets = $sets->copy();
        $this->status = WorkoutExerciseStatus::Completed;
    }

    public function skip(): void
    {
        if ($this->status === WorkoutExerciseStatus::Skipped) {
            return;
        }

        $this->assertEditable();

        $this->sets = new WorkoutSetCollection;
        $this->status = WorkoutExerciseStatus::Skipped;
    }

    public function reopen(): void
    {
        if ($this->status === WorkoutExerciseStatus::Skipped) {
            $this->sets = $this->plannedSets->copy();
        }

        $this->status = WorkoutExerciseStatus::Pending;
    }

    public function __clone(): void
    {
        $this->plannedSets = $this->plannedSets->copy();
        $this->sets = $this->sets->copy();
    }

    private function assertEditable(): void
    {
        if ($this->status !== WorkoutExerciseStatus::Pending) {
            throw new WorkoutExerciseIsNotEditable(
                $this->snapshot->exerciseId,
                $this->status,
            );
        }
    }
}
