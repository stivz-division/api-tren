<?php

namespace App\WorkoutAnalysis\Application\UseCases\GetWorkoutDeviationAnalysis;

final readonly class GetWorkoutDeviationAnalysisInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
    ) {}
}
