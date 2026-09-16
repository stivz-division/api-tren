<?php

use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('compares individual sets and aggregates a completed workout including skipped exercises', function () {
    $workout = Fixture::workout(
        Fixture::exercise([[10, 50_000], [8, 60_000]], [[12, 50_000], [6, 65_000]]),
        Fixture::exercise([[10, 20_000]], [], 20, 2, ExerciseCompletionStatus::Skipped),
    );

    $result = (new WorkoutDeviationCalculator)->calculate($workout);

    expect($result->snapshot)->toBe($workout);
    expect($result->completedExercises)->toBe(1);
    expect($result->skippedExercises)->toBe(1);
    expect($result->sets->planned)->toBe(3);
    expect($result->sets->actual)->toBe(2);
    expect($result->sets->difference)->toBe(-1);
    expect($result->repetitions->planned)->toBe(28);
    expect($result->repetitions->actual)->toBe(18);
    expect($result->volume->planned)->toBe(1_180_000);
    expect($result->volume->actual)->toBe(990_000);
    expect($result->volume->difference)->toBe(-190_000);

    $exercise = $result->exercises[0];
    expect($exercise->snapshot)->toBe($workout->exercises->all()[0]);
    expect($exercise->planFulfilled)->toBeFalse();
    expect($exercise->volume->difference)->toBe(10_000);
    expect($exercise->setComparisons[0]->repetitions?->difference)->toBe(2);
    expect($exercise->setComparisons[1]->workingWeight?->difference)->toBe(5_000);
    expect($exercise->setComparisons[1]->repetitions?->difference)->toBe(-2);
    expect($result->exercises[1]->planFulfilled)->toBeFalse();
    expect($result->exercises[1]->volume->percentage)->toBe(-100.0);
});

it('evaluates plan fulfillment at every planned position', function (ExercisePerformanceSnapshot $exercise, bool $fulfilled) {
    $result = (new WorkoutDeviationCalculator)->calculate(Fixture::workout($exercise));

    expect($result->exercises[0]->planFulfilled)->toBe($fulfilled);
})->with([
    'exact' => [fn () => Fixture::exercise([[10, 50_000], [8, 60_000]], [[10, 50_000], [8, 60_000]]), true],
    'higher on every position' => [fn () => Fixture::exercise([[10, 50_000]], [[11, 55_000]]), true],
    'extra sets allowed' => [fn () => Fixture::exercise([[10, 50_000]], [[10, 50_000], [1, 0]]), true],
    'extra set cannot compensate' => [fn () => Fixture::exercise([[10, 50_000]], [[9, 50_000], [20, 100_000]]), false],
    'higher weight cannot compensate repetitions' => [fn () => Fixture::exercise([[10, 50_000]], [[5, 100_000]]), false],
    'higher repetitions cannot compensate weight' => [fn () => Fixture::exercise([[10, 50_000]], [[20, 25_000]]), false],
    'another position cannot compensate' => [fn () => Fixture::exercise([[10, 50_000], [10, 50_000]], [[5, 50_000], [20, 50_000]]), false],
    'missing set despite greater volume' => [fn () => Fixture::exercise([[10, 50_000], [10, 50_000]], [[30, 50_000]]), false],
    'bodyweight fulfilled' => [fn () => Fixture::exercise([[10, 0]], [[10, 0]]), true],
    'bodyweight repetitions insufficient' => [fn () => Fixture::exercise([[10, 0]], [[9, 0]]), false],
]);

it('compares by order without guessing which planned set was omitted', function () {
    $exercise = Fixture::exercise([[10, 50_000], [8, 60_000], [6, 70_000]], [[10, 50_000], [6, 70_000]]);

    $result = (new WorkoutDeviationCalculator)->calculate(Fixture::workout($exercise))->exercises[0];

    expect($result->setComparisons)->toHaveCount(3);
    expect($result->setComparisons[1]->planned)->toBe($exercise->plannedSets->all()[1]);
    expect($result->setComparisons[1]->actual)->toBe($exercise->actualSets->all()[1]);
    expect($result->setComparisons[2]->planned)->toBe($exercise->plannedSets->all()[2]);
    expect($result->setComparisons[2]->actual)->toBeNull();
    expect($result->setComparisons[2]->repetitions)->toBeNull();
    expect($result->sets->difference)->toBe(-1);
});

it('preserves additional actual sets without inventing planned zero values', function () {
    $result = (new WorkoutDeviationCalculator)->calculate(Fixture::workout(
        Fixture::exercise([[10, 50_000]], [[10, 50_000], [4, 60_000]]),
    ))->exercises[0];

    expect($result->setComparisons)->toHaveCount(2);
    expect($result->setComparisons[1]->planned)->toBeNull();
    expect($result->setComparisons[1]->actual?->repetitions->value)->toBe(4);
    expect($result->setComparisons[1]->volume)->toBeNull();
    expect($result->sets->difference)->toBe(1);
    expect($result->volume->actual)->toBe(740_000);
});

it('keeps exercise failures when another exercise exceeds its plan', function () {
    $result = (new WorkoutDeviationCalculator)->calculate(Fixture::workout(
        Fixture::exercise([[10, 50_000]], [[5, 50_000]]),
        Fixture::exercise([[10, 50_000]], [[20, 50_000]], 20, 2),
    ));

    expect($result->volume->difference)->toBe(250_000);
    expect($result->completedExercises)->toBe(2);
    expect($result->exercises[0]->planFulfilled)->toBeFalse();
    expect($result->exercises[1]->planFulfilled)->toBeTrue();
});

it('supports a completed workout where every exercise was skipped', function () {
    $result = (new WorkoutDeviationCalculator)->calculate(Fixture::workout(
        Fixture::exercise([[10, 0]], [], status: ExerciseCompletionStatus::Skipped),
    ));

    expect($result->completedExercises)->toBe(0);
    expect($result->skippedExercises)->toBe(1);
    expect($result->sets->percentage)->toBe(-100.0);
    expect($result->repetitions->percentage)->toBe(-100.0);
    expect($result->volume->percentage)->toBeNull();
});

it('keeps gram precision for fractional kilogram weights', function () {
    $result = (new WorkoutDeviationCalculator)->calculate(Fixture::workout(
        Fixture::exercise([[3, 1_250]], [[3, 1_260]]),
    ));

    expect($result->volume->planned)->toBe(3_750);
    expect($result->volume->actual)->toBe(3_780);
    expect($result->volume->difference)->toBe(30);
});

it('produces the same result on repeated calculation without changing the snapshot', function () {
    $snapshot = Fixture::workout(Fixture::exercise());
    $calculator = new WorkoutDeviationCalculator;

    $first = $calculator->calculate($snapshot);
    $second = $calculator->calculate($snapshot);

    expect($second)->toEqual($first);
    expect($snapshot->exercises->all()[0]->actualSets->all()[0]->repetitions->value)->toBe(10);
});
