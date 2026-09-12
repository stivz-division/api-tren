<?php

namespace App\WorkoutPlanning\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class SetPosition
{
    public function __construct(public private(set) int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Позиция подхода должна быть не меньше одного.');
        }
    }
}
