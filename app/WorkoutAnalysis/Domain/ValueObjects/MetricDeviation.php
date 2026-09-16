<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class MetricDeviation
{
    public private(set) int $difference;

    public private(set) ?float $percentage;

    public function __construct(
        public private(set) int $planned,
        public private(set) int $actual,
    ) {
        if ($planned < 0 || $actual < 0) {
            throw new InvalidArgumentException('Плановое и фактическое значения не могут быть отрицательными.');
        }

        $this->difference = $actual - $planned;
        $this->percentage = $planned === 0 ? null : ($this->difference / $planned) * 100;
    }
}
