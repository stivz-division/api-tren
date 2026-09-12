<?php

namespace App\WorkoutPlanning\Application\DTO;

final readonly class PlannedExerciseInput
{
    /** @param list<PlannedSetInput> $sets */
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) array $sets,
    ) {}
}
