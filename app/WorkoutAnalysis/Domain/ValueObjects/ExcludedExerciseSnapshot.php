<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

final readonly class ExcludedExerciseSnapshot
{
    public function __construct(
        public private(set) ExerciseId $sourceExerciseId,
        public private(set) ExerciseId $planExerciseId,
        public private(set) ExercisePosition $position,
    ) {}
}
