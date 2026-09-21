<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution;

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\DTO\ExercisePerformanceData;
use App\WorkoutAnalysis\Application\DTO\SetSnapshotData;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutPlannedSetModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSetModel;
use LogicException;

final class CompletedWorkoutDataMapper
{
    public function toData(WorkoutSessionModel $session): CompletedWorkoutData
    {
        if (! $session->relationLoaded('workoutExercises')) {
            throw new LogicException('Упражнения исторической тренировки должны быть загружены.');
        }
        foreach ($session->workoutExercises as $exercise) {
            if (! $exercise->relationLoaded('plannedSets') || ! $exercise->relationLoaded('workoutSets')) {
                throw new LogicException('Плановые и фактические подходы должны быть загружены.');
            }
        }

        return new CompletedWorkoutData(
            $session->id, $session->user_id, $session->training_program_id, $session->training_program_name,
            $session->status, $session->completed_at?->utc()->toDateTimeImmutable(),
            array_values($session->workoutExercises->map(static fn (WorkoutExerciseModel $exercise): ExercisePerformanceData => new ExercisePerformanceData(
                $exercise->exercise_id, $exercise->exercise_name, $exercise->position, $exercise->status,
                array_values($exercise->plannedSets->map(static fn (WorkoutPlannedSetModel $set): SetSnapshotData => new SetSnapshotData(
                    $set->position, $set->repetitions, $set->working_weight_grams,
                ))->all()),
                array_values($exercise->workoutSets->map(static fn (WorkoutSetModel $set): SetSnapshotData => new SetSnapshotData(
                    $set->position, $set->repetitions, $set->working_weight_grams,
                ))->all()),
            ))->all()),
        );
    }
}
