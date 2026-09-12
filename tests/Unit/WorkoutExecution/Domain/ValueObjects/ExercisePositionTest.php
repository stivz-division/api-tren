<?php

use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;

it('accepts a positive exercise position', function () {
    expect((new ExercisePosition(1))->value)->toBe(1);
});

it('rejects a non-positive exercise position', function (int $value) {
    expect(fn () => new ExercisePosition($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
