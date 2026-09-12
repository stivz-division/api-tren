<?php

namespace App\WorkoutExecution\Domain\Enums;

enum WorkoutSessionStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
