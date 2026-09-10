<?php

use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\InvalidWeekday;

it('creates a weekday from a supported numeric value', function () {
    expect(Weekday::fromValue(3))->toBe(Weekday::Wednesday);
});

it('rejects an unsupported numeric value with a Russian error', function () {
    expect(fn () => Weekday::fromValue(0))->toThrow(
        InvalidWeekday::class,
        'День недели должен быть числом от 1 до 7.',
    );
});
