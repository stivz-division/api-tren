<?php

namespace App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram;

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;

final readonly class CreateTrainingProgramInput
{
    /** @param list<PlannedExerciseInput> $exercises */
    public function __construct(
        public private(set) int $userId,
        public private(set) int $weekday,
        public private(set) ?string $name,
        public private(set) array $exercises,
    ) {}
}
