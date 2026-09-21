<?php

namespace App\WorkoutAnalysis\Application\UseCases\ActOnWorkoutRecommendation;

final readonly class ActOnWorkoutRecommendationInput
{
    public function __construct(public int $userId, public int $recommendationId) {}
}
