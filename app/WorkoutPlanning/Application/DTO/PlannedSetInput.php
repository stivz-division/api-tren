<?php

namespace App\WorkoutPlanning\Application\DTO;

final readonly class PlannedSetInput
{
    public function __construct(
        public private(set) int $repetitions,
        public private(set) int $workingWeightInGrams,
    ) {}
}
