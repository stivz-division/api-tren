<?php

namespace App\WorkoutExecution\Infrastructure\Time;

use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class UtcWorkoutClock implements WorkoutClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
