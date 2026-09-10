<?php

namespace Tests\Support\WorkoutPlanning;

use App\WorkoutPlanning\Application\Gateways\ExerciseCatalog;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;

final class InMemoryExerciseCatalog implements ExerciseCatalog
{
    /** @var array<int, true> */
    private array $exerciseIds = [];

    public function __construct(int ...$exerciseIds)
    {
        foreach ($exerciseIds as $exerciseId) {
            $this->exerciseIds[$exerciseId] = true;
        }
    }

    public function findMissing(array $exerciseIds): array
    {
        return array_values(array_filter(
            $exerciseIds,
            fn (ExerciseId $exerciseId): bool => ! isset($this->exerciseIds[$exerciseId->value]),
        ));
    }
}
