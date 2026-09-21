<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutRecommendations;

final readonly class RecoverWorkoutRecommendationsInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
    ) {}
}
