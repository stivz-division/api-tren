<?php

namespace App\WorkoutExecution\Application\UseCases\ReplaceExercise;

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;

final readonly class ReplaceExerciseInput
{
    /** @param list<WorkoutSetInput> $sets */
    public function __construct(
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
        public private(set) int $exerciseId,
        public private(set) string $requestId,
        public private(set) int $replacementExerciseId,
        public private(set) array $sets,
        public private(set) bool $replaceInPlan = false,
    ) {}
}
