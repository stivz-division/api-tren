<?php

namespace Tests\Support\WorkoutPlanning;

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\DTO\PlannedSetInput;
use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;
use App\WorkoutPlanning\Domain\ValueObjects\Repetitions;
use App\WorkoutPlanning\Domain\ValueObjects\SetPosition;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

final class PlannedExerciseFixture
{
    public static function input(
        int $exerciseId = 10,
        int $setCount = 3,
        int $repetitions = 6,
        int $workingWeightInGrams = 100_000,
    ): PlannedExerciseInput {
        return new PlannedExerciseInput(
            $exerciseId,
            array_fill(0, $setCount, new PlannedSetInput($repetitions, $workingWeightInGrams)),
        );
    }

    public static function exercise(
        int $exerciseId = 10,
        int $position = 1,
        int $setCount = 3,
        int $repetitions = 6,
        int $workingWeightInGrams = 100_000,
    ): PlannedExercise {
        return new PlannedExercise(
            new ExerciseId($exerciseId),
            self::sets($setCount, $repetitions, $workingWeightInGrams),
            new ExercisePosition($position),
        );
    }

    public static function sets(
        int $count = 3,
        int $repetitions = 6,
        int $workingWeightInGrams = 100_000,
    ): PlannedSetCollection {
        return new PlannedSetCollection(...array_map(
            static fn (int $position): PlannedSet => new PlannedSet(
                new SetPosition($position),
                new Repetitions($repetitions),
                new WorkingWeight($workingWeightInGrams),
            ),
            range(1, $count),
        ));
    }
}
