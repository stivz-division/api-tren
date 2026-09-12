<?php

use App\WorkoutExecution\Domain\ValueObjects\SetsCount;

it('accepts a positive planned sets count', function () {
    expect((new SetsCount(3))->value)->toBe(3);
});

it('rejects a non-positive planned sets count', function (int $value) {
    expect(fn () => new SetsCount($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
