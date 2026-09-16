<?php

use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('rejects volume overflow before losing integer precision', function () {
    expect(fn () => Fixture::set(repetitions: 2, grams: PHP_INT_MAX)->volume())
        ->toThrow(OverflowException::class);
});

it('accepts the largest representable volume', function () {
    expect(Fixture::set(repetitions: 1, grams: PHP_INT_MAX)->volume())->toBe(PHP_INT_MAX);
});

it('keeps bodyweight volume zero for any valid repetition count', function () {
    expect(Fixture::set(repetitions: PHP_INT_MAX, grams: 0)->volume())->toBe(0);
});
