<?php

namespace App\WorkoutExecution\Application\UseCases\GetWorkoutSessionHistory;

final readonly class GetWorkoutSessionHistoryInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $perPage,
        public private(set) ?string $cursor,
    ) {}
}
