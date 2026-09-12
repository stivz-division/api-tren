<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class InvalidWorkoutSessionState extends DomainException
{
    public function __construct()
    {
        parent::__construct('Сохранённое состояние тренировочной сессии нарушает доменные инварианты.');
    }
}
