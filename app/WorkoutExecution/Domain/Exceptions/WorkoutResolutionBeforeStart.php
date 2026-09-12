<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class WorkoutResolutionBeforeStart extends DomainException
{
    public function __construct()
    {
        parent::__construct('Время завершения или отмены тренировки не может быть раньше времени её начала.');
    }
}
