<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class InvalidWorkoutExerciseReplacement extends DomainException
{
    public function __construct()
    {
        parent::__construct('Некорректная замена упражнения в тренировочной сессии.');
    }
}
