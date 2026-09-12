<?php

namespace App\WorkoutExecution\Application\UseCases\StartWorkoutSession;

final readonly class StartWorkoutSessionInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $trainingProgramId,
    ) {}
}
