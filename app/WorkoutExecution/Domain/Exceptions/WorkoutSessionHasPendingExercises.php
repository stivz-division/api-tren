<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class WorkoutSessionHasPendingExercises extends DomainException
{
    public function __construct()
    {
        parent::__construct('Нельзя завершить тренировочную сессию, пока остались незавершённые упражнения.');
    }
}
