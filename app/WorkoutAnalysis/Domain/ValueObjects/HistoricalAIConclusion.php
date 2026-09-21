<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;

final readonly class HistoricalAIConclusion
{
    /** @var list<AnalysisEvidenceReference> */
    public private(set) array $evidence;

    public function __construct(
        public private(set) WorkoutAnalysisId $analysisId,
        public private(set) WorkoutSessionId $workoutSessionId,
        public private(set) string $currentWorkout,
        public private(set) string $history,
        AnalysisEvidenceReference ...$evidence,
    ) {
        if (trim($currentWorkout) === '' || trim($history) === '') {
            throw new InvalidAnalysisContext('Оба раздела исторического заключения должны содержать текст.');
        }

        foreach ($evidence as $reference) {
            if ($reference->analysisId != $analysisId) {
                throw new InvalidAnalysisContext('Основание заключения относится к другому анализу.');
            }
        }

        $this->evidence = array_values($evidence);
    }
}
