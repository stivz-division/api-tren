<?php

namespace Tests\Support\WorkoutExecution;

use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use DateTimeImmutable;

final readonly class FrozenWorkoutClock implements WorkoutClock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
