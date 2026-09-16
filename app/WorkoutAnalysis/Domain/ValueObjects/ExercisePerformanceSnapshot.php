<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use InvalidArgumentException;

final readonly class ExercisePerformanceSnapshot
{
    public function __construct(
        public private(set) ExerciseId $exerciseId,
        public private(set) ExerciseName $name,
        public private(set) ExercisePosition $position,
        public private(set) ExerciseCompletionStatus $status,
        public private(set) SetSnapshotCollection $plannedSets,
        public private(set) SetSnapshotCollection $actualSets,
    ) {
        if ($plannedSets->count() === 0) {
            throw new InvalidArgumentException('Снимок упражнения должен содержать плановые подходы.');
        }

        if ($status === ExerciseCompletionStatus::Completed && $actualSets->count() === 0) {
            throw new InvalidArgumentException('Завершённое упражнение должно содержать фактические подходы.');
        }

        if ($status === ExerciseCompletionStatus::Skipped && $actualSets->count() !== 0) {
            throw new InvalidArgumentException('Пропущенное упражнение не может содержать фактические подходы.');
        }
    }
}
