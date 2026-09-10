<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use DomainException;

final class ExerciseAlreadyPlanned extends DomainException
{
    public function __construct(ExerciseId $exerciseId)
    {
        parent::__construct(sprintf(
            'Упражнение %d уже добавлено в программу тренировок.',
            $exerciseId->value,
        ));
    }
}
