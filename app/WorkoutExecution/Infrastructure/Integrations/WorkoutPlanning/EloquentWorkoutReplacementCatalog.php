<?php

namespace App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning;

use App\Models\Exercise;
use App\WorkoutExecution\Application\Gateways\WorkoutReplacementCatalog;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;

final readonly class EloquentWorkoutReplacementCatalog implements WorkoutReplacementCatalog
{
    public function findName(ExerciseId $exerciseId): ?ExerciseName
    {
        $exercise = Exercise::query()->find($exerciseId->value);

        return $exercise === null ? null : new ExerciseName($exercise->name);
    }
}
