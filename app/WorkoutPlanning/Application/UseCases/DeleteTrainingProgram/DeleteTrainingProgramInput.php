<?php

namespace App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram;

final readonly class DeleteTrainingProgramInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $trainingProgramId,
    ) {}
}
