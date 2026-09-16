<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use OverflowException;

final readonly class WorkoutSetSnapshot
{
    public function __construct(
        public private(set) SetPosition $position,
        public private(set) Repetitions $repetitions,
        public private(set) WorkingWeight $workingWeight,
    ) {}

    /** Объём внешней нагрузки в граммах × повторениях. */
    public function volume(): int
    {
        if ($this->workingWeight->grams > intdiv(PHP_INT_MAX, $this->repetitions->value)) {
            throw new OverflowException('Объём подхода превышает диапазон целых чисел.');
        }

        return $this->workingWeight->grams * $this->repetitions->value;
    }
}
