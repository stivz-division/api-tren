<?php

namespace App\WorkoutAnalysis\Application\DTO;

final readonly class ExcludedExerciseData
{
    public function __construct(
        public private(set) int $sourceExerciseId,
        public private(set) int $planExerciseId,
        public private(set) int $position,
    ) {}
}
