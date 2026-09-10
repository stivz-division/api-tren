<?php

namespace App\WorkoutPlanning\Application\DTO;

final readonly class PlannedExerciseInput
{
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) int $sets,
        public private(set) int $repetitionsPerSet,
        public private(set) int $workingWeightInGrams,
    ) {}
}
