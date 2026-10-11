<?php

namespace App\WorkoutExecution\Application\DTO;

use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;

final readonly class WorkoutExerciseReplacementDTO
{
    public function __construct(
        public private(set) string $requestId,
        public private(set) bool $replaceInPlan,
    ) {}

    public static function fromDomain(WorkoutExerciseReplacement $replacement): self
    {
        return new self($replacement->requestId, $replacement->replaceInPlan);
    }
}
