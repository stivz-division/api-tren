<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;

final readonly class SetSnapshotData
{
    public function __construct(
        public private(set) int $position,
        public private(set) int $repetitions,
        public private(set) int $workingWeightInGrams,
    ) {}

    public static function fromDomain(WorkoutSetSnapshot $set): self
    {
        return new self($set->position->value, $set->repetitions->value, $set->workingWeight->grams);
    }
}
