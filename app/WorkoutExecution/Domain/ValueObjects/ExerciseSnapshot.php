<?php

namespace App\WorkoutExecution\Domain\ValueObjects;

final readonly class ExerciseSnapshot
{
    public function __construct(
        public private(set) ExerciseId $exerciseId,
        public private(set) ExerciseName $name,
        public private(set) ExercisePosition $position,
    ) {}
}
