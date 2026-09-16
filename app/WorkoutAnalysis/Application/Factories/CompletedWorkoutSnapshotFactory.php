<?php

namespace App\WorkoutAnalysis\Application\Factories;

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\DTO\ExercisePerformanceData;
use App\WorkoutAnalysis\Application\DTO\SetSnapshotData;
use App\WorkoutAnalysis\Application\Exceptions\CompletedWorkoutNotFound;
use App\WorkoutAnalysis\Application\Exceptions\InvalidCompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutNotCompleted;
use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseName;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePosition;
use App\WorkoutAnalysis\Domain\ValueObjects\ProgramName;
use App\WorkoutAnalysis\Domain\ValueObjects\Repetitions;
use App\WorkoutAnalysis\Domain\ValueObjects\SetPosition;
use App\WorkoutAnalysis\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkingWeight;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;
use InvalidArgumentException;
use ValueError;

final class CompletedWorkoutSnapshotFactory
{
    public function create(CompletedWorkoutData $data, UserId $userId, WorkoutSessionId $sessionId): CompletedWorkoutSnapshot
    {
        if ($data->userId !== $userId->value || $data->workoutSessionId !== $sessionId->value) {
            throw new CompletedWorkoutNotFound;
        }
        if ($data->status !== 'completed') {
            throw new WorkoutNotCompleted;
        }
        if ($data->completedAt === null) {
            throw new InvalidCompletedWorkoutSnapshot('У завершённой тренировки отсутствует время завершения.');
        }

        try {
            return new CompletedWorkoutSnapshot(
                $sessionId,
                $userId,
                new TrainingProgramId($data->trainingProgramId),
                new ProgramName($data->programName),
                $data->completedAt,
                new ExercisePerformanceCollection(...array_map(
                    fn (ExercisePerformanceData $exercise): ExercisePerformanceSnapshot => new ExercisePerformanceSnapshot(
                        new ExerciseId($exercise->exerciseId),
                        new ExerciseName($exercise->name),
                        new ExercisePosition($exercise->position),
                        ExerciseCompletionStatus::from($exercise->status),
                        $this->sets($exercise->plannedSets),
                        $this->sets($exercise->actualSets),
                    ),
                    $data->exercises,
                )),
            );
        } catch (InvalidArgumentException|ValueError $exception) {
            throw new InvalidCompletedWorkoutSnapshot('Некорректный снимок завершённой тренировки.', previous: $exception);
        }
    }

    /** @param list<SetSnapshotData> $sets */
    private function sets(array $sets): SetSnapshotCollection
    {
        return new SetSnapshotCollection(...array_map(
            static fn (SetSnapshotData $set): WorkoutSetSnapshot => new WorkoutSetSnapshot(
                new SetPosition($set->position),
                new Repetitions($set->repetitions),
                new WorkingWeight($set->workingWeightInGrams),
            ),
            $sets,
        ));
    }
}
