<?php

namespace App\WorkoutAnalysis\Domain\Enums;

enum AnalysisFailureCode: string
{
    case RecommendationsRejected = 'recommendations_rejected';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderRejected = 'provider_rejected';
    case AIRefused = 'ai_refused';
    case InvalidAIResponse = 'invalid_ai_response';
    case IncompleteAIResponse = 'incomplete_ai_response';
    case ContextPreparationFailed = 'context_preparation_failed';
    case CalculationFailed = 'calculation_failed';
    case ArithmeticOverflow = 'arithmetic_overflow';
    case WorkerFailed = 'worker_failed';
    case AttemptTimedOut = 'attempt_timed_out';
}
