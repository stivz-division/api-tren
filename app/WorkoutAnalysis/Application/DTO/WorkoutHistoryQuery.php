<?php

namespace App\WorkoutAnalysis\Application\DTO;

use DateTimeImmutable;

final readonly class WorkoutHistoryQuery
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $currentWorkoutSessionId,
        public private(set) int $trainingProgramId,
        public private(set) DateTimeImmutable $completedBefore,
        public private(set) int $sameProgramLimit,
        public private(set) int $otherProgramsLimit,
    ) {}
}
