<?php

namespace App\WorkoutAnalysis\Domain\Enums;

enum AnalysisFailureCode: string
{
    case CalculationFailed = 'calculation_failed';
    case ArithmeticOverflow = 'arithmetic_overflow';
    case WorkerFailed = 'worker_failed';
    case AttemptTimedOut = 'attempt_timed_out';
}
