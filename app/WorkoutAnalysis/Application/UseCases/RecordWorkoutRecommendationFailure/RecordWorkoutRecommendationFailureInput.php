<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecordWorkoutRecommendationFailure;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use InvalidArgumentException;

final readonly class RecordWorkoutRecommendationFailureInput
{
    /** @param list<string> $rejectedReasons */
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
        public private(set) int $attemptNumber,
        public private(set) AnalysisFailureCode $failureCode,
        public private(set) array $rejectedReasons = [],
    ) {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('Номер попытки должен быть положительным.');
        }
    }
}
