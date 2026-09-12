<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use App\WorkoutExecution\Domain\ValueObjects\UserId;
use DomainException;

final class ActiveWorkoutSessionAlreadyExists extends DomainException
{
    public function __construct(UserId $userId)
    {
        parent::__construct("У пользователя {$userId->value} уже есть активная тренировочная сессия.");
    }
}
