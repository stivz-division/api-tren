<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAIAnalysis;

final readonly class RecoverWorkoutAIAnalysisInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
    ) {}
}
