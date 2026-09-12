<?php

namespace App\WorkoutExecution\Application\UseCases\SaveExerciseProgress;

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;

final readonly class SaveExerciseProgressInput
{
    /** @param list<WorkoutSetInput> $sets */
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
        public private(set) int $exerciseId,
        public private(set) array $sets,
    ) {}
}
