<?php

namespace App\WorkoutAnalysis\Application\Policies;

use InvalidArgumentException;

final readonly class AnalysisHistoryPolicy
{
    public function __construct(
        public private(set) int $sameProgramLimit = 20,
        public private(set) int $otherProgramsLimit = 20,
    ) {
        if ($sameProgramLimit < 1 || $otherProgramsLimit < 1) {
            throw new InvalidArgumentException('Лимиты обоих окон истории должны быть положительными.');
        }
    }
}
