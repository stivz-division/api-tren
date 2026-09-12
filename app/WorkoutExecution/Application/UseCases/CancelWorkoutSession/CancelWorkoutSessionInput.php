<?php

namespace App\WorkoutExecution\Application\UseCases\CancelWorkoutSession;

final readonly class CancelWorkoutSessionInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
    ) {}
}
