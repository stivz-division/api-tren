<?php

use App\WorkoutAnalysis\Domain\ValueObjects\SetComparison;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('calculates separate weight repetition and volume deviations for a paired position', function () {
    $comparison = new SetComparison(Fixture::set(1, 10, 50_000), Fixture::set(1, 8, 60_000));

    expect($comparison->position->value)->toBe(1);
    expect($comparison->repetitions?->difference)->toBe(-2);
    expect($comparison->workingWeight?->difference)->toBe(10_000);
    expect($comparison->volume?->difference)->toBe(-20_000);
});

it('leaves all deviations undefined when a position has no counterpart', function (bool $hasPlan) {
    $set = Fixture::set();

    $comparison = new SetComparison($hasPlan ? $set : null, $hasPlan ? null : $set);

    expect($comparison->position->value)->toBe(1);
    expect($comparison->repetitions)->toBeNull();
    expect($comparison->workingWeight)->toBeNull();
    expect($comparison->volume)->toBeNull();
})->with(['planned only' => [true], 'actual only' => [false]]);

it('rejects comparing different positions', function () {
    expect(fn () => new SetComparison(Fixture::set(1), Fixture::set(2)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a comparison without either set', function () {
    expect(fn () => new SetComparison(null, null))->toThrow(InvalidArgumentException::class);
});
