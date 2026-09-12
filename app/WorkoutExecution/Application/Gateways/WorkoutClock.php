<?php

namespace App\WorkoutExecution\Application\Gateways;

use DateTimeImmutable;

interface WorkoutClock
{
    public function now(): DateTimeImmutable;
}
