<?php

namespace App\WorkoutExecution\Application\Exceptions;

use RuntimeException;

final class WorkoutSessionNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Тренировочная сессия не найдена.');
    }
}
