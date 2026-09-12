<?php

namespace App\WorkoutPlanning\Domain\ValueObjects;

final readonly class PlannedSet
{
    public function __construct(
        public private(set) SetPosition $position,
        public private(set) Repetitions $repetitions,
        public private(set) WorkingWeight $workingWeight,
    ) {}
}
