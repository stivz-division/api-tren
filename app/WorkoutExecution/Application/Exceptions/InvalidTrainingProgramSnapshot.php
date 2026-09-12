<?php

namespace App\WorkoutExecution\Application\Exceptions;

use RuntimeException;

final class InvalidTrainingProgramSnapshot extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Снимок программы тренировок должен содержать хотя бы одно упражнение.');
    }
}
