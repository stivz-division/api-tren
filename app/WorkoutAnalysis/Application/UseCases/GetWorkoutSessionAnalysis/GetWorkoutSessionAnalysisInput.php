<?php

namespace App\WorkoutAnalysis\Application\UseCases\GetWorkoutSessionAnalysis;

final readonly class GetWorkoutSessionAnalysisInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
    ) {}
}
