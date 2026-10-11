<?php

use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExcludedExerciseSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePosition;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('preserves the workout identity ownership program and completion time', function () {
    $snapshot = Fixture::workout(Fixture::exercise());

    expect($snapshot->workoutSessionId->value)->toBe(51);
    expect($snapshot->userId->value)->toBe(7);
    expect($snapshot->trainingProgramId->value)->toBe(11);
    expect($snapshot->programName->value)->toBe('Грудь и трицепс');
    expect($snapshot->completedAt->format(DATE_ATOM))->toBe('2026-09-15T12:00:00+00:00');
});

it('rejects a completed workout without exercises', function () {
    expect(fn () => Fixture::workout())->toThrow(InvalidArgumentException::class);
});

it('keeps its exercise list independent of arrays returned to callers', function () {
    $snapshot = Fixture::workout(Fixture::exercise());

    $exercises = $snapshot->exercises->all();
    array_pop($exercises);

    expect($snapshot->exercises)->toHaveCount(1);
});

it('compares included positions with gaps and counts excluded positions separately', function () {
    $original = Fixture::workout(Fixture::exercise());
    $snapshot = new CompletedWorkoutSnapshot(
        $original->workoutSessionId, $original->userId, $original->trainingProgramId,
        $original->programName, $original->completedAt,
        new ExercisePerformanceCollection(Fixture::exercise(position: 2)),
        [new ExcludedExerciseSnapshot(
            new ExerciseId(20),
            new ExerciseId(30),
            new ExercisePosition(1),
        )],
    );

    $result = (new WorkoutDeviationCalculator)->calculate($snapshot);

    expect($result->hasComparableExercises)->toBeTrue();
    expect($result->excludedExercisesCount)->toBe(1);
    expect($result->volume->planned)->toBe(500000);
    expect($snapshot->excludesPlanExercise(new ExerciseId(30)))->toBeTrue();
    expect($snapshot->excludesPlanExercise(new ExerciseId(20)))->toBeFalse();
});

it('returns no comparison and zero totals when every position is excluded', function () {
    $original = Fixture::workout(Fixture::exercise());
    $snapshot = new CompletedWorkoutSnapshot(
        $original->workoutSessionId, $original->userId, $original->trainingProgramId,
        $original->programName, $original->completedAt,
        new ExercisePerformanceCollection,
        [new ExcludedExerciseSnapshot(
            new ExerciseId(10),
            new ExerciseId(10),
            new ExercisePosition(1),
        )],
    );

    $result = (new WorkoutDeviationCalculator)->calculate($snapshot);

    expect($result->hasComparableExercises)->toBeFalse();
    expect($result->excludedExercisesCount)->toBe(1);
    expect($result->exercises)->toBe([]);
    expect($result->completedExercises)->toBe(0);
    expect($result->skippedExercises)->toBe(0);
    expect($result->volume->planned)->toBe(0);
    expect($result->volume->actual)->toBe(0);
});

it('rejects gaps and overlaps in the combined included and excluded positions', function (int $position) {
    $original = Fixture::workout(Fixture::exercise());

    expect(fn () => new CompletedWorkoutSnapshot(
        $original->workoutSessionId, $original->userId, $original->trainingProgramId,
        $original->programName, $original->completedAt, $original->exercises,
        [new ExcludedExerciseSnapshot(
            new ExerciseId(20),
            new ExerciseId(20),
            new ExercisePosition($position),
        )],
    ))->toThrow(InvalidArgumentException::class);
})->with(['overlap' => 1, 'gap' => 3]);

it('requires contiguous positions when there are no exclusion descriptors', function () {
    expect(fn () => Fixture::workout(Fixture::exercise(position: 2)))->toThrow(InvalidArgumentException::class);
});
