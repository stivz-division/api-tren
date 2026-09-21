<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;

final readonly class HistoricalWorkoutData
{
    public function __construct(
        public private(set) CompletedWorkoutData $workout,
        public private(set) ?WorkoutDeviationResult $deviations = null,
        public private(set) ?HistoricalAIConclusion $conclusion = null,
        public private(set) ?HistoricalRecommendationResult $recommendations = null,
    ) {}
}
