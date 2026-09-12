<?php

use App\WorkoutExecution\Domain\ValueObjects\UserId;

it('accepts a positive user identifier', function () {
    expect((new UserId(42))->value)->toBe(42);
});

it('rejects a non-positive user identifier', function (int $value) {
    expect(fn () => new UserId($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
