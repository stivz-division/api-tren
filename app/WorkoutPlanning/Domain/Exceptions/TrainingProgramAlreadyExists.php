<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use DomainException;

final class TrainingProgramAlreadyExists extends DomainException
{
    public function __construct(UserId $userId, Weekday $weekday)
    {
        parent::__construct(sprintf(
            'У пользователя %d уже есть программа тренировок на день недели %d.',
            $userId->value,
            $weekday->value,
        ));
    }
}
