<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

interface AIProvider
{
    public function analyze(WorkoutAnalysisId $analysisId, AnalysisContextSnapshot $context): WorkoutAIResult;
}
