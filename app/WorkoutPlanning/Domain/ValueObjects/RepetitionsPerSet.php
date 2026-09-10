<?php

namespace App\WorkoutPlanning\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class RepetitionsPerSet
{
    public function __construct(public private(set) int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Количество повторений в подходе должно быть не меньше одного.');
        }
    }
}
