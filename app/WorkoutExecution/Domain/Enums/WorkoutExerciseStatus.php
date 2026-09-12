<?php

namespace App\WorkoutExecution\Domain\Enums;

enum WorkoutExerciseStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Skipped = 'skipped';
}
