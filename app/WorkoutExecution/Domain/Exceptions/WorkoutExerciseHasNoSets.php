<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use DomainException;

final class WorkoutExerciseHasNoSets extends DomainException
{
    public function __construct(ExerciseId $exerciseId)
    {
        parent::__construct("Нельзя завершить упражнение {$exerciseId->value} без выполненных подходов.");
    }
}
