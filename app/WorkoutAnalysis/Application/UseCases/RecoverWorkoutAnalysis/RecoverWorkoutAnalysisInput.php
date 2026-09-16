<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAnalysis;

final readonly class RecoverWorkoutAnalysisInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
    ) {}
}
