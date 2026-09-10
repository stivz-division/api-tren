<?php

namespace App\WorkoutPlanning\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class TrainingProgramId
{
    public function __construct(public private(set) int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Идентификатор программы тренировок должен быть положительным целым числом.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
