<?php

namespace App\WorkoutAnalysis\Application\DTO;

final readonly class ExercisePerformanceData
{
    /**
     * @param  list<SetSnapshotData>  $plannedSets
     * @param  list<SetSnapshotData>  $actualSets
     */
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) string $name,
        public private(set) int $position,
        public private(set) string $status,
        public private(set) array $plannedSets,
        public private(set) array $actualSets,
    ) {}
}
