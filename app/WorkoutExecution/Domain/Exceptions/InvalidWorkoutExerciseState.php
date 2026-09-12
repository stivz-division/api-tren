<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class InvalidWorkoutExerciseState extends DomainException
{
    public function __construct()
    {
        parent::__construct('Сохранённое состояние упражнения нарушает доменные инварианты.');
    }
}
