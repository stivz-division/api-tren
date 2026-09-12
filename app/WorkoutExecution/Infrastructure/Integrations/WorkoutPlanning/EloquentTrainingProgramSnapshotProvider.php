<?php

namespace App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning;

use App\WorkoutExecution\Application\DTO\PlannedExerciseSnapshotData;
use App\WorkoutExecution\Application\DTO\TrainingProgramSnapshotData;
use App\WorkoutExecution\Application\Gateways\TrainingProgramSnapshotProvider;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedExerciseModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Database\DatabaseManager;

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

            $program->load('plannedExercises.exercise');
            $exercises = array_values($program->plannedExercises
                ->map(static fn (PlannedExerciseModel $exercise): PlannedExerciseSnapshotData => new PlannedExerciseSnapshotData(
                    $exercise->exercise_id,
                    $exercise->exercise->name,
                    $exercise->sets,
                    $exercise->repetitions_per_set,
                    $exercise->working_weight_grams,
                    $exercise->position,
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
