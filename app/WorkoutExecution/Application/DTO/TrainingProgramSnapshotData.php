<?php

namespace App\WorkoutExecution\Application\DTO;

final readonly class TrainingProgramSnapshotData
{
    /** @param list<PlannedExerciseSnapshotData> $exercises */
    public function __construct(
        public private(set) int $trainingProgramId,
        public private(set) string $name,
        public private(set) int $scheduledWeekday,
        public private(set) array $exercises,
    ) {}
}
