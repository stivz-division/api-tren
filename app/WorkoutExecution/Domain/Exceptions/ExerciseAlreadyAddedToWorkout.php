<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use DomainException;

final class ExerciseAlreadyAddedToWorkout extends DomainException
{
    public function __construct(ExerciseId $exerciseId)
    {
        parent::__construct("Упражнение {$exerciseId->value} уже добавлено в тренировочную сессию.");
    }
}
