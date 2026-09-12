<?php

namespace App\WorkoutExecution\Application\DTO;

final readonly class PlannedExerciseSnapshotData
{
    /** @param list<PlannedSetSnapshotData> $sets */
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) string $name,
        public private(set) array $sets,
        public private(set) int $position,
    ) {}
}
