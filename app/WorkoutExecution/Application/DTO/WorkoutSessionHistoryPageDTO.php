<?php

namespace App\WorkoutExecution\Application\DTO;

final readonly class WorkoutSessionHistoryPageDTO
{
    /** @param list<WorkoutSessionDTO> $sessions */
    public function __construct(
        public private(set) array $sessions,
        public private(set) int $perPage,
        public private(set) ?string $nextCursor,
        public private(set) ?string $previousCursor,
    ) {}
}
