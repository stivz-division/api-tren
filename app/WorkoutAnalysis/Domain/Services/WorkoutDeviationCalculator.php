<?php

namespace App\WorkoutAnalysis\Domain\Services;

use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseDeviation;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;

final class WorkoutDeviationCalculator
{
    public function calculate(CompletedWorkoutSnapshot $snapshot): WorkoutDeviationResult
    {
        return new WorkoutDeviationResult(
            $snapshot,
            ...array_map(
                static fn (ExercisePerformanceSnapshot $exercise): ExerciseDeviation => new ExerciseDeviation($exercise),
                $snapshot->exercises->all(),
            ),
        );
    }
}
