<?php

namespace App\WorkoutPlanning\Application\Gateways;

use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;

interface ExerciseCatalog
{
    /**
     * @param  list<ExerciseId>  $exerciseIds
     * @return list<ExerciseId>
     */
    public function findMissing(array $exerciseIds): array;
}
