<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use DomainException;

final class PlannedExerciseMustContainSet extends DomainException
{
    public function __construct()
    {
        parent::__construct('Запланированное упражнение должно содержать хотя бы один подход.');
    }
}
