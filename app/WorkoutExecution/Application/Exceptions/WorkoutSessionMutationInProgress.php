<?php

namespace App\WorkoutExecution\Application\Exceptions;

use RuntimeException;
use Throwable;

final class WorkoutSessionMutationInProgress extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'Изменение выполняемой тренировки уже выполняется.',
            0,
            $previous,
        );
    }
}
