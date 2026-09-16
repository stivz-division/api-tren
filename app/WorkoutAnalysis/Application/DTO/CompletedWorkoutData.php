<?php

namespace App\WorkoutAnalysis\Application\DTO;

use DateTimeImmutable;

final readonly class CompletedWorkoutData
{
    /** @param list<ExercisePerformanceData> $exercises */
    public function __construct(
        public private(set) int $workoutSessionId,
        public private(set) int $userId,
        public private(set) int $trainingProgramId,
        public private(set) string $programName,
        public private(set) string $status,
        public private(set) ?DateTimeImmutable $completedAt,
        public private(set) array $exercises,
    ) {}
}
