<?php

use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseDeviation;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('rejects an incomplete result', function () {
    $snapshot = Fixture::workout(Fixture::exercise());

    expect(fn () => new WorkoutDeviationResult($snapshot))->toThrow(InvalidArgumentException::class);
});

it('rejects results calculated from different exercise data', function () {
    $snapshot = Fixture::workout(Fixture::exercise());
    $other = new ExerciseDeviation(Fixture::exercise(actual: [[9, 50_000]]));

    expect(fn () => new WorkoutDeviationResult($snapshot, $other))->toThrow(InvalidArgumentException::class);
});

it('rejects results in a different order than the original workout', function () {
    $first = Fixture::exercise(id: 10, position: 1);
    $second = Fixture::exercise(id: 20, position: 2);
    $snapshot = Fixture::workout($first, $second);

    expect(fn () => new WorkoutDeviationResult(
        $snapshot,
        new ExerciseDeviation($second),
        new ExerciseDeviation($first),
    ))->toThrow(InvalidArgumentException::class);
});

it('accepts an equal immutable snapshot reconstructed independently', function () {
    $snapshot = Fixture::workout(Fixture::exercise());

    $result = new WorkoutDeviationResult($snapshot, new ExerciseDeviation(Fixture::exercise()));

    expect($result->sets->difference)->toBe(0);
    expect($result->completedExercises)->toBe(1);
});

it('detects overflow when combining individually valid exercise totals', function () {
    $snapshot = Fixture::workout(
        Fixture::exercise([[1, PHP_INT_MAX]], [[1, 0]]),
        Fixture::exercise([[1, 1]], [[1, 0]], 20, 2),
    );

    expect(fn () => (new WorkoutDeviationCalculator)->calculate($snapshot))->toThrow(OverflowException::class);
});
