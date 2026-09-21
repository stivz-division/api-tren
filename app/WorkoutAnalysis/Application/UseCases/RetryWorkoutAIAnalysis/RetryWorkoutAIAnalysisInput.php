<?php

namespace App\WorkoutAnalysis\Application\UseCases\RetryWorkoutAIAnalysis;

final readonly class RetryWorkoutAIAnalysisInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
    ) {}
}
