<?php

namespace App\WorkoutPlanning\Domain\Entities;

use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

final class PlannedExercise
{
    public function __construct(
        public private(set) readonly ExerciseId $exerciseId,
        public private(set) SetsCount $setsCount,
        public private(set) RepetitionsPerSet $repetitionsPerSet,
        public private(set) WorkingWeight $workingWeight,
        public private(set) ExercisePosition $position,
    ) {}

    public function changePrescription(
        SetsCount $setsCount,
        RepetitionsPerSet $repetitionsPerSet,
        WorkingWeight $workingWeight,
    ): void {
        $this->setsCount = $setsCount;
        $this->repetitionsPerSet = $repetitionsPerSet;
        $this->workingWeight = $workingWeight;
    }

    public function moveTo(ExercisePosition $position): void
    {
        $this->position = $position;
    }
}
