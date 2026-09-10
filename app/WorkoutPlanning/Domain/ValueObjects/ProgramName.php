<?php

namespace App\WorkoutPlanning\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ProgramName
{
    public private(set) string $value;

    public function __construct(string $value)
    {
        $value = mb_trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Название программы тренировок не может быть пустым.');
        }

        $this->value = $value;
    }

    public static function default(): self
    {
        return new self('Тренировка');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
