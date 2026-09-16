<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution;

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\DTO\ExercisePerformanceData;
use App\WorkoutAnalysis\Application\DTO\SetSnapshotData;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutPlannedSetModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSetModel;
use Illuminate\Database\DatabaseManager;

final readonly class EloquentCompletedWorkoutProvider implements CompletedWorkoutProvider
{
    public function __construct(private DatabaseManager $database) {}

    public function findForUser(WorkoutSessionId $sessionId, UserId $userId): ?CompletedWorkoutData
    {
        return $this->database->connection()->transaction(function () use ($sessionId, $userId): ?CompletedWorkoutData {
            $session = WorkoutSessionModel::query()->whereKey($sessionId->value)
                ->where('user_id', $userId->value)->sharedLock()->first();
            if ($session === null) {
                return null;
            }
            $session->load('workoutExercises.plannedSets', 'workoutExercises.workoutSets');

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
        });
    }
}
