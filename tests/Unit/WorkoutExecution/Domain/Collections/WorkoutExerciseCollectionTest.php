<?php

use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Exceptions\ExerciseAlreadyAddedToWorkout;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseOrder;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseNotFound;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

$workoutExercise = static fn (int $exerciseId, int $position): WorkoutExercise => WorkoutExercise::fromPlan(
    new ExerciseSnapshot(
        new ExerciseId($exerciseId),
        new ExerciseName('Упражнение '.$exerciseId),
        new ExercisePosition($position),
    ),
    WorkoutSessionFixture::sets(),
);

it('keeps exercises ordered by their planned positions', function () use ($workoutExercise) {
    $exercises = new WorkoutExerciseCollection(
        $workoutExercise(2, 2),
        $workoutExercise(1, 1),
    );

    expect(array_map(
        static fn (WorkoutExercise $exercise): int => $exercise->snapshot->exerciseId->value,
        $exercises->all(),
    ))->toBe([1, 2]);
});

it('rejects duplicate exercises', function () use ($workoutExercise) {
    expect(fn () => new WorkoutExerciseCollection(
        $workoutExercise(1, 1),
        $workoutExercise(1, 2),
    ))->toThrow(ExerciseAlreadyAddedToWorkout::class);
});

it('rejects non-contiguous exercise positions', function () use ($workoutExercise) {
    expect(fn () => new WorkoutExerciseCollection(
        $workoutExercise(1, 1),
        $workoutExercise(2, 3),
    ))->toThrow(InvalidWorkoutExerciseOrder::class);
});

it('rejects access to an exercise outside the workout', function () use ($workoutExercise) {
    $exercises = new WorkoutExerciseCollection($workoutExercise(1, 1));

    expect(fn () => $exercises->get(new ExerciseId(2)))
        ->toThrow(WorkoutExerciseNotFound::class);
});

it('reports whether every exercise is resolved', function () use ($workoutExercise) {
    $first = $workoutExercise(1, 1);
    $second = $workoutExercise(2, 2);
    $exercises = new WorkoutExerciseCollection($first, $second);

    $first->complete(new WorkoutSetCollection(...$first->workoutSets()));
    $second->skip();

    expect($exercises->allResolved())->toBeTrue();
});
