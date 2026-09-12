<?php

namespace App\WorkoutExecution\Application\UseCases\ReopenExercise;

final readonly class ReopenExerciseInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
        public private(set) int $exerciseId,
    ) {}
}
