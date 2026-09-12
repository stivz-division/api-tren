<?php

namespace App\WorkoutExecution\Application\DTO;

final readonly class PlannedSetSnapshotData
{
    public function __construct(
        public private(set) int $position,
        public private(set) int $repetitions,
        public private(set) int $workingWeightInGrams,
    ) {}
}
