<?php

use App\WorkoutExecution\Infrastructure\Time\UtcWorkoutClock;

it('returns the current server time in UTC', function (): void {
    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $now = (new UtcWorkoutClock)->now();

    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    expect($now->getTimezone()->getName())->toBe('UTC');
    expect($now >= $before && $now <= $after)->toBeTrue();
});
