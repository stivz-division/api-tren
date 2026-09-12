<?php

use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;

it('accepts a positive source exercise identifier', function () {
    expect((new ExerciseId(9))->value)->toBe(9);
});

it('rejects a non-positive source exercise identifier', function (int $value) {
    expect(fn () => new ExerciseId($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
