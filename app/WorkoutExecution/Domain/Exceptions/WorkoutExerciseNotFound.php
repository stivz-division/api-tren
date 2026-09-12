<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use DomainException;

final class WorkoutExerciseNotFound extends DomainException
{
    public function __construct(ExerciseId $exerciseId)
    {
        parent::__construct("Упражнение {$exerciseId->value} отсутствует в тренировочной сессии.");
    }
}
