<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

final readonly class AnalysisEvidenceReference
{
    public function __construct(
        public private(set) WorkoutAnalysisId $analysisId,
        public private(set) WorkoutSessionId $workoutSessionId,
        public private(set) ?ExerciseId $exerciseId = null,
    ) {}
}
