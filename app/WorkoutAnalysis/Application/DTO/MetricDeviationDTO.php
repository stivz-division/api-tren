<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\MetricDeviation;

final readonly class MetricDeviationDTO
{
    public function __construct(
        public private(set) int $planned,
        public private(set) int $actual,
        public private(set) int $difference,
        public private(set) ?float $percentage,
    ) {}

    public static function fromDomain(MetricDeviation $value): self
    {
        return new self($value->planned, $value->actual, $value->difference, $value->percentage);
    }
}
