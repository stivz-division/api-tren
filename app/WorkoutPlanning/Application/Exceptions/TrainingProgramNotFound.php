<?php

namespace App\WorkoutPlanning\Application\Exceptions;

use RuntimeException;

final class TrainingProgramNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Программа тренировок не найдена.');
    }
}
