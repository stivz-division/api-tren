<?php

namespace App\WorkoutExecution\Application\DTO;

use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;

final readonly class WorkoutSetDTO
{
    public function __construct(
        public private(set) int $position,
        public private(set) int $repetitions,
        public private(set) int $workingWeightInGrams,
    ) {}

    public static function fromDomain(WorkoutSet $set): self
    {
        return new self(
            position: $set->position->value,
            repetitions: $set->repetitions->value,
            workingWeightInGrams: $set->workingWeight->grams,
        );
    }
}
