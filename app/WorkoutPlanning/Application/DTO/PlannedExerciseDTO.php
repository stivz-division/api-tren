<?php

namespace App\WorkoutPlanning\Application\DTO;

use App\WorkoutPlanning\Domain\Entities\PlannedExercise;

final readonly class PlannedExerciseDTO
{
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) int $sets,
        public private(set) int $repetitionsPerSet,
        public private(set) int $workingWeightInGrams,
        public private(set) int $position,
    ) {}

    public static function fromDomain(PlannedExercise $plannedExercise): self
    {
        return new self(
            exerciseId: $plannedExercise->exerciseId->value,
            sets: $plannedExercise->setsCount->value,
            repetitionsPerSet: $plannedExercise->repetitionsPerSet->value,
            workingWeightInGrams: $plannedExercise->workingWeight->grams,
            position: $plannedExercise->position->value,
        );
    }
}
