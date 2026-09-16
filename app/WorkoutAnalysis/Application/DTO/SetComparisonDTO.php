<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\SetComparison;

final readonly class SetComparisonDTO
{
    public function __construct(
        public private(set) int $position,
        public private(set) ?SetSnapshotData $planned,
        public private(set) ?SetSnapshotData $actual,
        public private(set) ?MetricDeviationDTO $repetitions,
        public private(set) ?MetricDeviationDTO $workingWeight,
        public private(set) ?MetricDeviationDTO $volume,
    ) {}

    public static function fromDomain(SetComparison $value): self
    {
        return new self($value->position->value,
            $value->planned === null ? null : SetSnapshotData::fromDomain($value->planned),
            $value->actual === null ? null : SetSnapshotData::fromDomain($value->actual),
            $value->repetitions === null ? null : MetricDeviationDTO::fromDomain($value->repetitions),
            $value->workingWeight === null ? null : MetricDeviationDTO::fromDomain($value->workingWeight),
            $value->volume === null ? null : MetricDeviationDTO::fromDomain($value->volume));
    }
}
