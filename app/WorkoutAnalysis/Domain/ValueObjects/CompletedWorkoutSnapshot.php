<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CompletedWorkoutSnapshot
{
    public function __construct(
        public private(set) WorkoutSessionId $workoutSessionId,
        public private(set) UserId $userId,
        public private(set) TrainingProgramId $trainingProgramId,
        public private(set) ProgramName $programName,
        public private(set) DateTimeImmutable $completedAt,
        public private(set) ExercisePerformanceCollection $exercises,
    ) {
        if ($exercises->count() === 0) {
            throw new InvalidArgumentException('Снимок завершённой тренировки должен содержать упражнения.');
        }
    }
}
