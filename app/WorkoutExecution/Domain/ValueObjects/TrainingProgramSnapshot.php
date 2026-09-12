<?php

namespace App\WorkoutExecution\Domain\ValueObjects;

use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;

final readonly class TrainingProgramSnapshot
{
    public function __construct(
        public private(set) TrainingProgramId $trainingProgramId,
        public private(set) ProgramName $name,
        public private(set) ScheduledWeekday $scheduledWeekday,
    ) {}
}
