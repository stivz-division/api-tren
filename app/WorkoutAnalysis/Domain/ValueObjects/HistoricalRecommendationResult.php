<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;

final readonly class HistoricalRecommendationResult
{
    /** @var list<HistoricalRecommendation> */
    public private(set) array $recommendations;

    public function __construct(
        public private(set) WorkoutAnalysisId $analysisId,
        public private(set) WorkoutSessionId $workoutSessionId,
        HistoricalRecommendation ...$recommendations,
    ) {
        $ids = [];
        $exerciseIds = [];

        foreach ($recommendations as $recommendation) {
            if (isset($ids[$recommendation->id]) || isset($exerciseIds[$recommendation->exerciseId->value])) {
                throw new InvalidAnalysisContext('Рекомендации результата должны иметь уникальные ID и целевые упражнения.');
            }

            foreach ($recommendation->evidence as $reference) {
                if ($reference->analysisId != $analysisId) {
                    throw new InvalidAnalysisContext('Основание рекомендации относится к другому анализу.');
                }
            }

            $ids[$recommendation->id] = true;
            $exerciseIds[$recommendation->exerciseId->value] = true;
        }

        $this->recommendations = array_values($recommendations);
    }
}
