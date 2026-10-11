<?php

namespace App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning;

use App\WorkoutExecution\Application\DTO\PlannedExerciseSnapshotData;
use App\WorkoutExecution\Application\DTO\PlannedSetSnapshotData;
use App\WorkoutExecution\Application\DTO\TrainingProgramSnapshotData;
use App\WorkoutExecution\Application\Gateways\TrainingProgramSnapshotProvider;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedExerciseModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedSetModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Database\DatabaseManager;
use UnexpectedValueException;

final readonly class EloquentTrainingProgramSnapshotProvider implements TrainingProgramSnapshotProvider
{
    public function __construct(private DatabaseManager $database) {}

    public function findForUser(
        TrainingProgramId $trainingProgramId,
        UserId $userId,
    ): ?TrainingProgramSnapshotData {
        return $this->database->transaction(function () use ($trainingProgramId, $userId): ?TrainingProgramSnapshotData {
            $program = TrainingProgramModel::query()
                ->whereKey($trainingProgramId->value)
                ->where('user_id', $userId->value)
                ->sharedLock()
                ->first();

            if ($program === null) {
                return null;
            }

            $program->load('plannedExercises.exercise', 'plannedExercises.plannedSets');
            $revisions = $this->database->table('workout_plan_change_barriers')
                ->where('user_id', $userId->value)
                ->where('training_program_id', $trainingProgramId->value)
                ->whereIn('exercise_id', $program->plannedExercises->pluck('exercise_id'))
                ->sharedLock()
                ->pluck('revision', 'exercise_id')
                ->map(static function (mixed $revision): int {
                    if (! is_int($revision) && ! is_string($revision)) {
                        throw new UnexpectedValueException('Некорректная версия плана.');
                    }

                    return (int) $revision;
                });
            $exercises = array_values($program->plannedExercises
                ->map(static fn (PlannedExerciseModel $exercise): PlannedExerciseSnapshotData => new PlannedExerciseSnapshotData(
                    $exercise->exercise_id,
                    $exercise->exercise->name,
                    array_values($exercise->plannedSets
                        ->map(static fn (PlannedSetModel $set): PlannedSetSnapshotData => new PlannedSetSnapshotData(
                            $set->position,
                            $set->repetitions,
                            $set->working_weight_grams,
                        ))
                        ->all()),
                    $exercise->position,
                    $revisions->get($exercise->exercise_id, 0),
                ))
                ->all());

            return new TrainingProgramSnapshotData(
                $program->id,
                $program->name,
                $program->weekday,
                $exercises,
            );
        });
    }
}
