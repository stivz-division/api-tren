<?php

namespace App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession;

final readonly class CompleteWorkoutSessionInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
    ) {}
}
