<?php

namespace App\WorkoutAnalysis\Application\UseCases\RetryWorkoutRecommendations;

final readonly class RetryWorkoutRecommendationsInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
    ) {}
}
