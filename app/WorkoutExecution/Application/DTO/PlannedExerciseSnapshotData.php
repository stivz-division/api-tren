<?php

namespace App\WorkoutExecution\Application\DTO;

final readonly class PlannedExerciseSnapshotData
{
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) string $name,
        public private(set) int $sets,
        public private(set) int $repetitionsPerSet,
        public private(set) int $workingWeightInGrams,
        public private(set) int $position,
    ) {}
}
