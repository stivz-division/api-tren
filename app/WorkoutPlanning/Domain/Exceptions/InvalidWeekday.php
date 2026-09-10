<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use DomainException;

final class InvalidWeekday extends DomainException
{
    public function __construct()
    {
        parent::__construct('День недели должен быть числом от 1 до 7.');
    }
}
