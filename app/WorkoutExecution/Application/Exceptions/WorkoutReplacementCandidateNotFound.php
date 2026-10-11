<?php

namespace App\WorkoutExecution\Application\Exceptions;

use RuntimeException;

final class WorkoutReplacementCandidateNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Упражнение для замены не найдено.');
    }
}
