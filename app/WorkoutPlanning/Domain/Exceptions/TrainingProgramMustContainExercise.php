<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use DomainException;

final class TrainingProgramMustContainExercise extends DomainException
{
    public function __construct()
    {
        parent::__construct('Программа тренировок должна содержать хотя бы одно упражнение.');
    }
}
