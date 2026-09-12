<?php

namespace App\WorkoutExecution\Application\UseCases\SkipExercise;

final readonly class SkipExerciseInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
        public private(set) int $exerciseId,
    ) {}
}
