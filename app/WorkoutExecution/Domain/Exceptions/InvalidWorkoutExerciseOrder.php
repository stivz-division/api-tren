<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class InvalidWorkoutExerciseOrder extends DomainException
{
    public function __construct()
    {
        parent::__construct('Позиции упражнений должны быть уникальными и идти подряд, начиная с единицы.');
    }
}
