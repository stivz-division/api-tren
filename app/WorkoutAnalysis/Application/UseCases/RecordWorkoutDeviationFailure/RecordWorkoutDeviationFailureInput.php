<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecordWorkoutDeviationFailure;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use InvalidArgumentException;

final readonly class RecordWorkoutDeviationFailureInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
        public private(set) int $attemptNumber,
        public private(set) AnalysisFailureCode $failureCode,
    ) {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('Номер попытки должен быть положительным.');
        }
    }
}
