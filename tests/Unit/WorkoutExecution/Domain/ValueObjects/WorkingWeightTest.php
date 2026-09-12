<?php

use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;

it('preserves weight as an integer number of grams', function () {
    expect((new WorkingWeight(90_000))->grams)->toBe(90_000);
});

it('allows zero weight for bodyweight exercises', function () {
    expect((new WorkingWeight(0))->grams)->toBe(0);
});

it('rejects a negative working weight', function () {
    expect(fn () => new WorkingWeight(-1))->toThrow(InvalidArgumentException::class);
});
