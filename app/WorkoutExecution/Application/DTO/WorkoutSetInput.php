<?php

namespace App\WorkoutExecution\Application\DTO;

final readonly class WorkoutSetInput
{
    public function __construct(
        public private(set) int $repetitions,
        public private(set) int $workingWeightInGrams,
    ) {}
}
