<?php

namespace App\WorkoutAnalysis\Domain\Enums;

enum ExerciseCompletionStatus: string
{
    case Completed = 'completed';
    case Skipped = 'skipped';
}
