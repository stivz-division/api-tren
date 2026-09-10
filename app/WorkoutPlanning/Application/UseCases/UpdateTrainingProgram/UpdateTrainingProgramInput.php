<?php

namespace App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram;

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;

final readonly class UpdateTrainingProgramInput
{
    /** @param list<PlannedExerciseInput> $exercises */
    public function __construct(
        public private(set) int $userId,
        public private(set) int $trainingProgramId,
        public private(set) string $name,
        public private(set) array $exercises,
    ) {}
}
