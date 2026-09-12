<?php

namespace App\WorkoutPlanning\Domain\Entities;

use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;

final class PlannedExercise
{
    private PlannedSetCollection $sets;

    public function __construct(
        public private(set) readonly ExerciseId $exerciseId,
        PlannedSetCollection $sets,
        public private(set) ExercisePosition $position,
    ) {
        $this->sets = $sets->copy();
    }

    /** @return list<PlannedSet> */
    public function plannedSets(): array
    {
        return $this->sets->copy()->all();
    }

    public function replaceSets(PlannedSetCollection $sets): void
    {
        $this->sets = $sets->copy();
    }

    public function moveTo(ExercisePosition $position): void
    {
        $this->position = $position;
    }

    public function __clone(): void
    {
        $this->sets = $this->sets->copy();
    }
}
