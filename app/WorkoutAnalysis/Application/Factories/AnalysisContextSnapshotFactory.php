<?php

namespace App\WorkoutAnalysis\Application\Factories;

use App\WorkoutAnalysis\Application\DTO\HistoricalWorkoutData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryData;
use App\WorkoutAnalysis\Application\Policies\AnalysisHistoryPolicy;
use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use DateTimeImmutable;

final readonly class AnalysisContextSnapshotFactory
{
    public function __construct(
        private CompletedWorkoutSnapshotFactory $workouts,
        private WorkoutDeviationCalculator $calculator,
    ) {}

    public function create(
        WorkoutDeviationResult $currentWorkout,
        WorkoutHistoryData $history,
        AnalysisHistoryPolicy $policy,
        DateTimeImmutable $capturedAt,
    ): AnalysisContextSnapshot {
        if (count($history->sameProgram) > $policy->sameProgramLimit || count($history->otherPrograms) > $policy->otherProgramsLimit) {
            throw new InvalidAnalysisContext('Провайдер вернул историю сверх запрошенных лимитов.');
        }

        $entry = fn (HistoricalWorkoutData $data): WorkoutHistoryEntry => $this->entry($data, $currentWorkout->snapshot->userId);

        return new AnalysisContextSnapshot(
            $currentWorkout,
            new WorkoutHistoryWindow($policy->sameProgramLimit, ...array_map($entry, $history->sameProgram)),
            new WorkoutHistoryWindow($policy->otherProgramsLimit, ...array_map($entry, $history->otherPrograms)),
            $capturedAt,
        );
    }

    private function entry(HistoricalWorkoutData $data, UserId $userId): WorkoutHistoryEntry
    {
        $snapshot = $this->workouts->create($data->workout, $userId, new WorkoutSessionId($data->workout->workoutSessionId));
        if ($data->deviations !== null && $data->deviations->snapshot != $snapshot) {
            throw new InvalidAnalysisContext('Сохранённые отклонения не соответствуют историческому снимку.');
        }

        return new WorkoutHistoryEntry(
            $data->deviations ?? $this->calculator->calculate($snapshot),
            $data->conclusion,
            $data->recommendations,
        );
    }
}
