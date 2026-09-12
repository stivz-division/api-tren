<?php

namespace Tests\Support\WorkoutExecution;

use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;
use App\WorkoutExecution\Domain\Enums\WorkoutSessionStatus;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\ProgramName;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use DateTimeImmutable;
use DateTimeZone;

final class WorkoutSessionFixture
{
    public static function active(
        int $id = 51,
        int $userId = 7,
        int $trainingProgramId = 11,
    ): WorkoutSession {
        return WorkoutSession::restore(
            new WorkoutSessionId($id),
            new UserId($userId),
            new TrainingProgramSnapshot(
                new TrainingProgramId($trainingProgramId),
                new ProgramName('Грудь и трицепс'),
                ScheduledWeekday::Monday,
            ),
            new WorkoutExerciseCollection(
                self::exercise(10, 'Жим лежа', 1, 3, 8, 90_000),
                self::exercise(20, 'Разгибание на трицепс', 2, 3, 12, 36_000),
            ),
            WorkoutSessionStatus::InProgress,
            new DateTimeImmutable('2026-09-15 19:00:00', new DateTimeZone('Europe/Moscow')),
            null,
            null,
        );
    }

    private static function exercise(
        int $exerciseId,
        string $name,
        int $position,
        int $sets,
        int $repetitions,
        int $weightInGrams,
    ): WorkoutExercise {
        return WorkoutExercise::fromPlan(
            new ExerciseSnapshot(
                new ExerciseId($exerciseId),
                new ExerciseName($name),
                new ExercisePosition($position),
            ),
            self::sets($sets, $repetitions, $weightInGrams),
        );
    }

    public static function sets(
        int $count = 3,
        int $repetitions = 8,
        int $weightInGrams = 90_000,
    ): WorkoutSetCollection {
        return new WorkoutSetCollection(...array_map(
            static fn (int $position): WorkoutSet => new WorkoutSet(
                new SetPosition($position),
                new Repetitions($repetitions),
                new WorkingWeight($weightInGrams),
            ),
            range(1, $count),
        ));
    }
}
