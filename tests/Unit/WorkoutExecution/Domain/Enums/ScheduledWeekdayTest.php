<?php

use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;
use App\WorkoutExecution\Domain\Exceptions\InvalidScheduledWeekday;

it('maps persisted weekday values', function () {
    expect(ScheduledWeekday::fromValue(1))->toBe(ScheduledWeekday::Monday);
    expect(ScheduledWeekday::fromValue(7))->toBe(ScheduledWeekday::Sunday);
});

it('rejects a value outside the week', function () {
    expect(fn () => ScheduledWeekday::fromValue(8))->toThrow(InvalidScheduledWeekday::class);
});
