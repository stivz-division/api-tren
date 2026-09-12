<?php

namespace App\WorkoutPlanning\Application\DTO;

use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;

final readonly class PlannedSetDTO
{
    public function __construct(
        public private(set) int $position,
        public private(set) int $repetitions,
        public private(set) int $workingWeightInGrams,
    ) {}

    public static function fromDomain(PlannedSet $set): self
    {
        return new self(
            position: $set->position->value,
            repetitions: $set->repetitions->value,
            workingWeightInGrams: $set->workingWeight->grams,
        );
    }
}
