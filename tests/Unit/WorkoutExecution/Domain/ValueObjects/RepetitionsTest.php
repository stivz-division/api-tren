<?php

use App\WorkoutExecution\Domain\ValueObjects\Repetitions;

it('accepts a positive repetitions count', function () {
    expect((new Repetitions(8))->value)->toBe(8);
});

it('rejects a non-positive repetitions count', function (int $value) {
    expect(fn () => new Repetitions($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
