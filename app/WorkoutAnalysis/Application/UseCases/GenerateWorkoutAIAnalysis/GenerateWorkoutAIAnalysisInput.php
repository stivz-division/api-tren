<?php

namespace App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis;

final readonly class GenerateWorkoutAIAnalysisInput
{
    public function __construct(public int $userId, public int $analysisId, public int $attemptNumber) {}
}
