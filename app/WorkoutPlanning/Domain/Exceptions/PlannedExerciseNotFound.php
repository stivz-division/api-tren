<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use DomainException;

final class PlannedExerciseNotFound extends DomainException
{
    public function __construct(ExerciseId $exerciseId)
    {
        parent::__construct(sprintf(
            'Упражнение %d отсутствует в программе тренировок.',
            $exerciseId->value,
        ));
    }
}
