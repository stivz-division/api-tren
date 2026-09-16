<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseDeviation;

final readonly class ExerciseDeviationDTO
{
    /** @param list<SetComparisonDTO> $setComparisons */
    public function __construct(
        public private(set) ExercisePerformanceData $snapshot,
        public private(set) array $setComparisons,
        public private(set) MetricDeviationDTO $sets,
        public private(set) MetricDeviationDTO $repetitions,
        public private(set) MetricDeviationDTO $volume,
        public private(set) bool $planFulfilled,
    ) {}

    public static function fromDomain(ExerciseDeviation $value): self
    {
        $snapshot = $value->snapshot;

        return new self(
            new ExercisePerformanceData(
                $snapshot->exerciseId->value,
                $snapshot->name->value,
                $snapshot->position->value,
                $snapshot->status->value,
                array_map(SetSnapshotData::fromDomain(...), $snapshot->plannedSets->all()),
                array_map(SetSnapshotData::fromDomain(...), $snapshot->actualSets->all()),
            ),
            array_map(SetComparisonDTO::fromDomain(...), $value->setComparisons),
            MetricDeviationDTO::fromDomain($value->sets),
            MetricDeviationDTO::fromDomain($value->repetitions),
            MetricDeviationDTO::fromDomain($value->volume),
            $value->planFulfilled,
        );
    }
}
