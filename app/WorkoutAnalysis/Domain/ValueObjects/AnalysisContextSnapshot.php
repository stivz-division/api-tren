<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use DateTimeImmutable;

final readonly class AnalysisContextSnapshot
{
    public function __construct(
        public private(set) WorkoutDeviationResult $currentWorkout,
        public private(set) WorkoutHistoryWindow $sameProgram,
        public private(set) WorkoutHistoryWindow $otherPrograms,
        public private(set) DateTimeImmutable $capturedAt,
    ) {
        $current = $currentWorkout->snapshot;

        if ($capturedAt < $current->completedAt) {
            throw new InvalidAnalysisContext('Контекст нельзя зафиксировать до завершения тренировки.');
        }

        $sessionIds = [$current->workoutSessionId->value => true];

        foreach ([$sameProgram, $otherPrograms] as $index => $window) {
            foreach ($window as $entry) {
                $historical = $entry->deviations->snapshot;
                if (
                    $historical->userId != $current->userId
                    || $historical->completedAt >= $current->completedAt
                    || (($historical->trainingProgramId == $current->trainingProgramId) !== ($index === 0))
                    || isset($sessionIds[$historical->workoutSessionId->value])
                ) {
                    throw new InvalidAnalysisContext('История нарушает границы пользователя, программы, времени или уникальности сессий.');
                }

                $sessionIds[$historical->workoutSessionId->value] = true;

                foreach ($entry->recommendations->recommendations ?? [] as $recommendation) {
                    foreach ([$recommendation->appliedAt, $recommendation->rejectedAt, $recommendation->expiredAt] as $actionAt) {
                        if ($actionAt !== null && ($actionAt < $historical->completedAt || $actionAt > $capturedAt)) {
                            throw new InvalidAnalysisContext('Время действия с рекомендацией выходит за границы её истории и фиксации контекста.');
                        }
                    }
                }
            }
        }
    }
}
