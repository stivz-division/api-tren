<?php

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutSetOrder;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;

$workoutSet = static fn (int $position, int $repetitions = 8, int $weight = 90_000): WorkoutSet => new WorkoutSet(
    new SetPosition($position),
    new Repetitions($repetitions),
    new WorkingWeight($weight),
);

it('preserves every individual planned set value', function () use ($workoutSet) {
    $sets = new WorkoutSetCollection(
        $workoutSet(1, 3, 80_000),
        $workoutSet(2, 6, 100_000),
        $workoutSet(3, 1, 130_000),
    );

    expect(array_map(
        static fn (WorkoutSet $set): array => [$set->position->value, $set->repetitions->value, $set->workingWeight->grams],
        $sets->all(),
    ))->toBe([
        [1, 3, 80_000],
        [2, 6, 100_000],
        [3, 1, 130_000],
    ]);
});

it('allows an empty progress snapshot', function () {
    expect(new WorkoutSetCollection)->toHaveCount(0);
});

it('keeps sets ordered by their contiguous positions', function () use ($workoutSet) {
    $sets = new WorkoutSetCollection($workoutSet(2), $workoutSet(1));

    expect(array_map(
        static fn (WorkoutSet $set): int => $set->position->value,
        $sets->all(),
    ))->toBe([1, 2]);
});

it('rejects duplicate set positions', function () use ($workoutSet) {
    expect(fn () => new WorkoutSetCollection(
        $workoutSet(1),
        $workoutSet(1),
    ))->toThrow(InvalidWorkoutSetOrder::class);
});

it('rejects non-contiguous set positions', function () use ($workoutSet) {
    expect(fn () => new WorkoutSetCollection(
        $workoutSet(1),
        $workoutSet(3),
    ))->toThrow(InvalidWorkoutSetOrder::class);
});
