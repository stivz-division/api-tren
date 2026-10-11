<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution;

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\DTO\ExcludedExerciseData;
use App\WorkoutAnalysis\Application\DTO\ExercisePerformanceData;
use App\WorkoutAnalysis\Application\DTO\SetSnapshotData;
use App\WorkoutAnalysis\Application\Exceptions\InvalidCompletedWorkoutSnapshot;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseReplacementModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutPlannedSetModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutReplacementPlannedSetModel;
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
        $included = [];
        $excluded = [];
        foreach ($session->workoutExercises as $exercise) {
            $this->assertLoaded($exercise);
            $latest = $exercise->replacements->last();
            if ($latest !== null && ! $latest->replace_in_plan) {
                if ($session->status === 'completed' && ! in_array($exercise->status, ['completed', 'skipped'], true)) {
                    throw new InvalidCompletedWorkoutSnapshot('Исключённое упражнение завершённой тренировки должно быть завершено или пропущено.');
                }
                $baseline = $exercise->replacements->last(
                    static fn (WorkoutExerciseReplacementModel $replacement): bool => $replacement->replace_in_plan,
                );
                $excluded[] = new ExcludedExerciseData(
                    $exercise->exercise_id,
                    $baseline->exercise_id ?? $exercise->exercise_id,
                    $exercise->position,
                );

                continue;
            }

            $plannedSets = $latest->plannedSets ?? $exercise->plannedSets;
            $included[] = new ExercisePerformanceData(
                $latest->exercise_id ?? $exercise->exercise_id,
                $latest->exercise_name ?? $exercise->exercise_name,
                $exercise->position,
                $exercise->status,
                array_values($plannedSets->map($this->setData(...))->all()),
                array_values($exercise->workoutSets->map($this->setData(...))->all()),
            );
        }

        return new CompletedWorkoutData(
            $session->id,
            $session->user_id,
            $session->training_program_id,
            $session->training_program_name,
            $session->status,
            $session->completed_at?->utc()->toDateTimeImmutable(),
            $included,
            $excluded,
        );
    }

    private function assertLoaded(WorkoutExerciseModel $exercise): void
    {
        if (! $exercise->relationLoaded('plannedSets') || ! $exercise->relationLoaded('workoutSets')) {
            throw new LogicException('Плановые и фактические подходы должны быть загружены.');
        }
        if (! $exercise->relationLoaded('replacements')) {
            throw new LogicException('Замены исторического упражнения должны быть загружены.');
        }
        foreach ($exercise->replacements as $replacement) {
            if (! $replacement->relationLoaded('plannedSets')) {
                throw new LogicException('Плановые подходы замен должны быть загружены.');
            }
        }
    }

    private function setData(WorkoutPlannedSetModel|WorkoutReplacementPlannedSetModel|WorkoutSetModel $set): SetSnapshotData
    {
        return new SetSnapshotData($set->position, $set->repetitions, $set->working_weight_grams);
    }
}
