<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class InvalidScheduledWeekday extends DomainException
{
    public function __construct()
    {
        parent::__construct('День недели должен быть целым числом от 1 до 7.');
    }
}
