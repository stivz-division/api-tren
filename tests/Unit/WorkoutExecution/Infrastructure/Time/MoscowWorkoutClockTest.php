<?php

use App\WorkoutExecution\Infrastructure\Time\MoscowWorkoutClock;

it('returns the current server time in Europe Moscow', function (): void {
    $before = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));

    $now = (new MoscowWorkoutClock)->now();

    $after = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));

    expect($now->getTimezone()->getName())->toBe('Europe/Moscow');
    expect($now >= $before && $now <= $after)->toBeTrue();
});
