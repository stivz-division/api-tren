<?php

namespace Tests\Support\WorkoutAnalysis;

use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
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
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;
use DateTimeImmutable;

final class WorkoutAnalysisFixture
{
    public static function result(
        int $sessionId = 51,
        int $programId = 11,
        int $userId = 7,
        string $completedAt = '2026-09-15 12:00:00+00:00',
        ?ExercisePerformanceSnapshot $exercise = null,
    ): WorkoutDeviationResult {
        return (new WorkoutDeviationCalculator)->calculate(new CompletedWorkoutSnapshot(
            new WorkoutSessionId($sessionId),
            new UserId($userId),
            new TrainingProgramId($programId),
            new ProgramName('Грудь и трицепс'),
            new DateTimeImmutable($completedAt),
            new ExercisePerformanceCollection($exercise ?? self::exercise()),
        ));
    }

    public static function workout(ExercisePerformanceSnapshot ...$exercises): CompletedWorkoutSnapshot
    {
        return new CompletedWorkoutSnapshot(
            new WorkoutSessionId(51),
            new UserId(7),
            new TrainingProgramId(11),
            new ProgramName('Грудь и трицепс'),
            new DateTimeImmutable('2026-09-15 12:00:00+00:00'),
            new ExercisePerformanceCollection(...$exercises),
        );
    }

    /**
     * @param  non-empty-list<array{int, int}>  $planned
     * @param  list<array{int, int}>  $actual
     */
    public static function exercise(
        array $planned = [[10, 50_000]],
        array $actual = [[10, 50_000]],
        int $id = 10,
        int $position = 1,
        ExerciseCompletionStatus $status = ExerciseCompletionStatus::Completed,
    ): ExercisePerformanceSnapshot {
        return new ExercisePerformanceSnapshot(
            new ExerciseId($id),
            new ExerciseName('Жим лежа'),
            new ExercisePosition($position),
            $status,
            self::sets($planned),
            self::sets($actual),
        );
    }

    /** @param list<array{int, int}> $sets */
    public static function sets(array $sets): SetSnapshotCollection
    {
        $snapshots = [];

        foreach ($sets as $index => [$repetitions, $grams]) {
            $snapshots[] = self::set($index + 1, $repetitions, $grams);
        }

        return new SetSnapshotCollection(...$snapshots);
    }

    public static function set(int $position = 1, int $repetitions = 10, int $grams = 50_000): WorkoutSetSnapshot
    {
        return new WorkoutSetSnapshot(
            new SetPosition($position),
            new Repetitions($repetitions),
            new WorkingWeight($grams),
        );
    }
}
