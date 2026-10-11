<?php

namespace App\WorkoutExecution\Application\Exceptions;

use RuntimeException;

final class WorkoutReplacementPlanConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('План тренировки изменён или недоступен для замены.');
    }
}
