<?php

namespace App\WorkoutExecution\Domain\ValueObjects;

final readonly class PlannedPrescription
{
    public function __construct(
        public private(set) SetsCount $setsCount,
        public private(set) Repetitions $repetitionsPerSet,
        public private(set) WorkingWeight $workingWeight,
    ) {}
}
