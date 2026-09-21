<?php

namespace App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext;

final readonly class PrepareWorkoutAnalysisContextInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
    ) {}
}
