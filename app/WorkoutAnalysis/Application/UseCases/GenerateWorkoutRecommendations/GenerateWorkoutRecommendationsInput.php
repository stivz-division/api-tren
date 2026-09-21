<?php

namespace App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutRecommendations;

final readonly class GenerateWorkoutRecommendationsInput
{
    public function __construct(public int $userId, public int $analysisId, public int $attemptNumber) {}
}
