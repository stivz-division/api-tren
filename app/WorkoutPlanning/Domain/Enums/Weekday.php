<?php

namespace App\WorkoutPlanning\Domain\Enums;

use App\WorkoutPlanning\Domain\Exceptions\InvalidWeekday;

enum Weekday: int
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
        return self::tryFrom($value) ?? throw new InvalidWeekday;
    }
}
