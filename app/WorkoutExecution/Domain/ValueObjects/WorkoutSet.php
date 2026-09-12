<?php

namespace App\WorkoutExecution\Domain\ValueObjects;

final readonly class WorkoutSet
{
    public function __construct(
        public private(set) SetPosition $position,
        public private(set) Repetitions $repetitions,
        public private(set) WorkingWeight $workingWeight,
    ) {}
}
