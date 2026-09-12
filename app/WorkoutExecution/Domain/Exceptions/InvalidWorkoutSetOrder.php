<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class InvalidWorkoutSetOrder extends DomainException
{
    public function __construct()
    {
        parent::__construct('Позиции подходов должны быть уникальными и идти подряд, начиная с единицы.');
    }
}
