<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use App\WorkoutExecution\Domain\Enums\WorkoutSessionStatus;
use DomainException;

final class WorkoutSessionIsNotInProgress extends DomainException
{
    public function __construct(WorkoutSessionStatus $status)
    {
        parent::__construct("Тренировочную сессию нельзя изменить в статусе {$status->value}.");
    }
}
