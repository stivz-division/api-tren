<?php

namespace App\WorkoutPlanning\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class WorkingWeight
{
    public function __construct(public private(set) int $grams)
    {
        if ($grams < 0) {
            throw new InvalidArgumentException('Рабочий вес не может быть отрицательным.');
        }
    }
}
