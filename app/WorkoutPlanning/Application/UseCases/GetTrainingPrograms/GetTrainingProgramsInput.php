<?php

namespace App\WorkoutPlanning\Application\UseCases\GetTrainingPrograms;

final readonly class GetTrainingProgramsInput
{
    public function __construct(public private(set) int $userId) {}
}
