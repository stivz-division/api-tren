<?php

namespace App\WorkoutExecution\Application\Gateways;

use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;

interface WorkoutReplacementCatalog
{
    public function findName(ExerciseId $exerciseId): ?ExerciseName;
}
