<?php

namespace App\WorkoutAnalysis\Application\UseCases\RetryWorkoutDeviationAnalysis;

final readonly class RetryWorkoutDeviationAnalysisInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
    ) {}
}
