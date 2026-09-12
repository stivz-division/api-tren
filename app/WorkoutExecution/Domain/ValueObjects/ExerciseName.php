<?php

namespace App\WorkoutExecution\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ExerciseName
{
    public private(set) string $value;

    public function __construct(string $value)
    {
        $value = mb_trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Название упражнения не может быть пустым.');
        }

        $this->value = $value;
    }
}
