<?php

namespace App\WorkoutExecution\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ExercisePosition
{
    public function __construct(public private(set) int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Позиция упражнения должна быть положительным целым числом.');
        }
    }
}
