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
use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;
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

it('rejects duplicate effective exercises in restored collections', function () use ($workoutExercise) {
    $first = $workoutExercise(1, 1);
    $first->replace(new WorkoutExerciseReplacement(
        1, '550e8400-e29b-41d4-a716-000000000001',
        new ExerciseSnapshot(new ExerciseId(2), new ExerciseName('Новое'), new ExercisePosition(1)),
        WorkoutSessionFixture::sets(), false, new DateTimeImmutable('2026-10-09 UTC'),
    ));

    expect(fn () => new WorkoutExerciseCollection($first, $workoutExercise(2, 2)))
        ->toThrow(ExerciseAlreadyAddedToWorkout::class);
});

it('copies replacement history without sharing mutable exercise state', function () use ($workoutExercise) {
    $first = $workoutExercise(1, 1);
    $first->replace(new WorkoutExerciseReplacement(
        1, '550e8400-e29b-41d4-a716-000000000001',
        new ExerciseSnapshot(new ExerciseId(2), new ExerciseName('Новое'), new ExercisePosition(1)),
        WorkoutSessionFixture::sets(), false, new DateTimeImmutable('2026-10-09 UTC'),
    ));
    $original = new WorkoutExerciseCollection($first);
    $copy = $original->copy();

    $copy->get(new ExerciseId(1))->skip();

    expect($original->get(new ExerciseId(1))->workoutSets())->toHaveCount(3);
    expect($copy->get(new ExerciseId(1))->workoutSets())->toBe([]);
    expect($copy->get(new ExerciseId(1))->latestReplacement())->toBe($first->latestReplacement());
});
