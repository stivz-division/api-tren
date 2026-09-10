<?php

namespace App\WorkoutPlanning\Application\Exceptions;

use RuntimeException;
use Throwable;

final class TrainingProgramMutationInProgress extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'Изменение расписания тренировок уже выполняется.',
            previous: $previous,
        );
    }
}
