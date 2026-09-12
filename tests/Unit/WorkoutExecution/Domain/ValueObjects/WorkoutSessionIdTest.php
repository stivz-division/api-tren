<?php

use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;

it('accepts a positive workout session identifier', function () {
    expect((new WorkoutSessionId(3))->value)->toBe(3);
});

it('rejects a non-positive workout session identifier', function (int $value) {
    expect(fn () => new WorkoutSessionId($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
