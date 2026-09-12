<?php

namespace App\WorkoutExecution\Domain\Enums;

use App\WorkoutExecution\Domain\Exceptions\InvalidScheduledWeekday;

enum ScheduledWeekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public static function fromValue(int $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidScheduledWeekday;
    }
}
