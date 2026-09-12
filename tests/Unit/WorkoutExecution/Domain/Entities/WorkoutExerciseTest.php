<?php

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseState;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseHasNoSets;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseIsNotEditable;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\PlannedPrescription;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\SetsCount;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;

$createExercise = static fn (): WorkoutExercise => WorkoutExercise::fromPlan(
    new ExerciseSnapshot(
        new ExerciseId(10),
        new ExerciseName('Жим лежа'),
        new ExercisePosition(1),
    ),
    new PlannedPrescription(
        new SetsCount(3),
        new Repetitions(8),
        new WorkingWeight(90_000),
    ),
);

$changedSets = static fn (): WorkoutSetCollection => new WorkoutSetCollection(
    new WorkoutSet(new SetPosition(1), new Repetitions(8), new WorkingWeight(90_000)),
    new WorkoutSet(new SetPosition(2), new Repetitions(8), new WorkingWeight(100_000)),
    new WorkoutSet(new SetPosition(3), new Repetitions(7), new WorkingWeight(100_000)),
    new WorkoutSet(new SetPosition(4), new Repetitions(6), new WorkingWeight(95_000)),
);

it('initializes editable sets from the planned prescription', function () use ($createExercise) {
    $exercise = $createExercise();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
    expect($exercise->plannedPrescription->setsCount->value)->toBe(3);
    expect($exercise->workoutSets())->toHaveCount(3);
});

it('replaces the complete progress snapshot', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();

    $exercise->saveProgress($changedSets());

    expect(array_map(
        static fn (WorkoutSet $set): array => [$set->repetitions->value, $set->workingWeight->grams],
        $exercise->workoutSets(),
    ))->toBe([
        [8, 90_000],
        [8, 100_000],
        [7, 100_000],
        [6, 95_000],
    ]);
});

it('allows temporarily saving an empty progress snapshot', function () use ($createExercise) {
    $exercise = $createExercise();

    $exercise->saveProgress(new WorkoutSetCollection);

    expect($exercise->workoutSets())->toBe([]);
});

it('completes an exercise with at least one saved set', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->saveProgress($changedSets());

    $exercise->complete($changedSets());

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Completed);
});

it('allows retrying completion with the same sets', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->complete($changedSets());

    $exercise->complete($changedSets());

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Completed);
    expect($exercise->workoutSets())->toHaveCount(4);
});

it('rejects retrying completion with different sets', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->complete($changedSets());
    $differentSets = new WorkoutSetCollection(new WorkoutSet(
        new SetPosition(1),
        new Repetitions(5),
        new WorkingWeight(110_000),
    ));

    expect(fn () => $exercise->complete($differentSets))
        ->toThrow(WorkoutExerciseIsNotEditable::class);
});

it('rejects completing an exercise without saved sets', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->saveProgress(new WorkoutSetCollection);

    expect(fn () => $exercise->complete(new WorkoutSetCollection))
        ->toThrow(WorkoutExerciseHasNoSets::class);
});

it('keeps the previous draft when final completion sets are empty', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->saveProgress($changedSets());

    expect(fn () => $exercise->complete(new WorkoutSetCollection))
        ->toThrow(WorkoutExerciseHasNoSets::class);
    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
    expect($exercise->workoutSets())->toHaveCount(4);
});

it('clears saved progress when an exercise is skipped', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->saveProgress($changedSets());

    $exercise->skip();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Skipped);
    expect($exercise->workoutSets())->toBe([]);
});

it('allows retrying skip for an already skipped exercise', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->skip();

    $exercise->skip();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Skipped);
    expect($exercise->workoutSets())->toBe([]);
});

it('reopens a completed exercise for editing', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));

    $exercise->reopen();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
});

it('reopens a skipped exercise for editing', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->skip();

    $exercise->reopen();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
    expect(array_map(
        static fn (WorkoutSet $set): array => [
            $set->position->value,
            $set->repetitions->value,
            $set->workingWeight->grams,
        ],
        $exercise->workoutSets(),
    ))->toBe([
        [1, 8, 90_000],
        [2, 8, 90_000],
        [3, 8, 90_000],
    ]);
});

it('rejects progress changes until a resolved exercise is reopened', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));

    expect(fn () => $exercise->saveProgress($changedSets()))
        ->toThrow(WorkoutExerciseIsNotEditable::class);
});

it('rejects restoring a skipped exercise with saved sets', function () use ($createExercise) {
    $exercise = $createExercise();

    expect(fn () => WorkoutExercise::restore(
        $exercise->snapshot,
        $exercise->plannedPrescription,
        new WorkoutSetCollection(...$exercise->workoutSets()),
        WorkoutExerciseStatus::Skipped,
    ))->toThrow(InvalidWorkoutExerciseState::class);
});
