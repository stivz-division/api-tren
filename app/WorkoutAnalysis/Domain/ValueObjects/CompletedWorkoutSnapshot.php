<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CompletedWorkoutSnapshot
{
    /** @var list<ExcludedExerciseSnapshot> */
    public private(set) array $excludedExercises;

    /** @param list<ExcludedExerciseSnapshot> $excludedExercises */
    public function __construct(
        public private(set) WorkoutSessionId $workoutSessionId,
        public private(set) UserId $userId,
        public private(set) TrainingProgramId $trainingProgramId,
        public private(set) ProgramName $programName,
        public private(set) DateTimeImmutable $completedAt,
        public private(set) ExercisePerformanceCollection $exercises,
        array $excludedExercises = [],
    ) {
        if ($exercises->count() === 0 && $excludedExercises === []) {
            throw new InvalidArgumentException('Снимок завершённой тренировки должен содержать упражнения.');
        }

        $positions = array_map(
            static fn (ExercisePerformanceSnapshot $exercise): int => $exercise->position->value,
            $exercises->all(),
        );
        $sourceIds = [];
        foreach ($excludedExercises as $excluded) {
            if (isset($sourceIds[$excluded->sourceExerciseId->value])) {
                throw new InvalidArgumentException('Исключённая позиция не может повторяться в снимке.');
            }
            $sourceIds[$excluded->sourceExerciseId->value] = true;
            $positions[] = $excluded->position->value;
        }
        sort($positions);
        if ($positions !== range(1, count($positions))) {
            throw new InvalidArgumentException('Позиции учитываемых и исключённых упражнений должны быть уникальными и непрерывными, начиная с 1.');
        }
        usort($excludedExercises, static fn (ExcludedExerciseSnapshot $left, ExcludedExerciseSnapshot $right): int => $left->position->value <=> $right->position->value);
        $this->excludedExercises = $excludedExercises;
    }

    public function hasComparableExercises(): bool
    {
        return $this->exercises->count() > 0;
    }

    /** Checks descriptor membership, independent of included exercises at other positions. */
    public function excludesPlanExercise(ExerciseId $exerciseId): bool
    {
        foreach ($this->excludedExercises as $excluded) {
            if ($excluded->planExerciseId->value === $exerciseId->value) {
                return true;
            }
        }

        return false;
    }
}
