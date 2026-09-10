<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use DomainException;

final class InvalidExerciseOrder extends DomainException
{
    public function __construct()
    {
        parent::__construct('Порядок должен содержать каждое упражнение программы ровно один раз.');
    }
}
