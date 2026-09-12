<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use DomainException;

final class WorkoutExerciseIsNotEditable extends DomainException
{
    public function __construct(ExerciseId $exerciseId, WorkoutExerciseStatus $status)
    {
        parent::__construct(
            "Упражнение {$exerciseId->value} нельзя редактировать в статусе {$status->value}. Сначала откройте его повторно.",
        );
    }
}
