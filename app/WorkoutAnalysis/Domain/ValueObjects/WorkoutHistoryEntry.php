<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;

final readonly class WorkoutHistoryEntry
{
    public function __construct(
        public private(set) WorkoutDeviationResult $deviations,
        public private(set) ?HistoricalAIConclusion $conclusion = null,
        public private(set) ?HistoricalRecommendationResult $recommendations = null,
    ) {
        $sessionId = $deviations->snapshot->workoutSessionId;

        if (
            ($conclusion !== null && $conclusion->workoutSessionId != $sessionId)
            || ($recommendations !== null && $recommendations->workoutSessionId != $sessionId)
            || ($conclusion !== null && $recommendations !== null && $conclusion->analysisId != $recommendations->analysisId)
        ) {
            throw new InvalidAnalysisContext('Исторические дополнения не соответствуют тренировке или исходному анализу.');
        }
    }
}
