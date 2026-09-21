<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class RecommendationEligibility
{
    public function __construct(
        public int $exerciseId,
        public int $successes,
        public int $failures,
        public int $completedSinceReplacement,
        public int $completedSinceRejection,
        public bool $currentlySuccessful,
    ) {
        if ($exerciseId < 1 || min($successes, $failures, $completedSinceReplacement, $completedSinceRejection) < 0) {
            throw new InvalidArgumentException('Счётчики допуска должны быть неотрицательными.');
        }
    }

    public function allows(string $changeType): bool
    {
        return match ($changeType) {
            'progression' => $this->successes >= 3,
            'adjustment' => $this->failures >= 2,
            'replacement' => ($this->failures >= 2 || ($this->completedSinceReplacement >= 28 && $this->currentlySuccessful))
                && $this->completedSinceRejection >= 4,
            default => false,
        };
    }
}
