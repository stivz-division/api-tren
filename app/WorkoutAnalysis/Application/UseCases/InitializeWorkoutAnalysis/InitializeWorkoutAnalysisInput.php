<?php

namespace App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis;

final readonly class InitializeWorkoutAnalysisInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
    ) {}
}
