<?php

namespace App\WorkoutExecution\Application\UseCases\CompleteExercise;

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;

final readonly class CompleteExerciseInput
{
    /** @param list<WorkoutSetInput> $sets */
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
        public private(set) int $exerciseId,
        public private(set) array $sets,
    ) {}
}
