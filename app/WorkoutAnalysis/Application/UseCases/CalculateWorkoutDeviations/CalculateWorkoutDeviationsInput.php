<?php

namespace App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations;

use InvalidArgumentException;

final readonly class CalculateWorkoutDeviationsInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $analysisId,
        public private(set) int $attemptNumber,
    ) {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('Номер попытки должен быть положительным.');
        }
    }
}
