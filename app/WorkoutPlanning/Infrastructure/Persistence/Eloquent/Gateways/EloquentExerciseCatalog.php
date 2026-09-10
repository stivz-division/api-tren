<?php

namespace App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Gateways;

use App\Models\Exercise;
use App\WorkoutPlanning\Application\Gateways\ExerciseCatalog;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;

final readonly class EloquentExerciseCatalog implements ExerciseCatalog
{
    public function findMissing(array $exerciseIds): array
    {
        if ($exerciseIds === []) {
            return [];
        }

        $requestedIds = array_map(
            static fn (ExerciseId $exerciseId): int => $exerciseId->value,
            $exerciseIds,
        );
        $existingIds = Exercise::query()
            ->whereKey($requestedIds)
            ->get(['id'])
            ->map(static fn (Exercise $exercise): int => $exercise->id)
            ->all();
        $existingIdsByValue = array_fill_keys($existingIds, true);

        return array_values(array_filter(
            $exerciseIds,
            static fn (ExerciseId $exerciseId): bool => ! isset($existingIdsByValue[$exerciseId->value]),
        ));
    }
}
