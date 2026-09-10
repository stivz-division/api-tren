<?php

namespace App\WorkoutPlanning\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ExerciseId
{
    public function __construct(public private(set) int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Идентификатор упражнения должен быть положительным целым числом.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
