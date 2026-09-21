<?php

namespace App\WorkoutAnalysis\Domain\Services;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationEligibility;
use DateTimeImmutable;

final class RecommendationCounterCalculator
{
    /** @param list<CompletedWorkoutSnapshot> $history */
    public function calculate(
        int $exerciseId,
        SetSnapshotCollection $currentPlan,
        array $history,
        ?DateTimeImmutable $lastReplacementAt = null,
        ?DateTimeImmutable $lastRejectedAt = null,
        ?DateTimeImmutable $lastPlanChangeAt = null,
    ): RecommendationEligibility {
        usort($history, static fn (CompletedWorkoutSnapshot $left, CompletedWorkoutSnapshot $right): int => ($left->completedAt <=> $right->completedAt) ?: ($left->workoutSessionId->value <=> $right->workoutSessionId->value));
        $sinceReplacement = 0;
        $sinceRejection = 0;
        foreach ($history as $workout) {
            $sinceReplacement += (int) ($lastReplacementAt === null || $workout->completedAt > $lastReplacementAt);
            $sinceRejection += (int) ($lastRejectedAt === null || $workout->completedAt > $lastRejectedAt);
        }
        $successes = 0;
        $failures = 0;
        $currentlySuccessful = false;
        foreach (array_reverse($history) as $index => $workout) {
            if ($lastPlanChangeAt !== null && $workout->completedAt <= $lastPlanChangeAt) {
                break;
            }
            $performance = null;
            foreach ($workout->exercises as $exercise) {
                if ($exercise->exerciseId->value === $exerciseId) {
                    $performance = $exercise;
                    break;
                }
            }
            if ($performance === null || $performance->plannedSets != $currentPlan) {
                break;
            }
            $successful = $this->successful($performance);
            if ($index === 0) {
                $currentlySuccessful = $successful;
            }
            if (($successful && $failures > 0) || (! $successful && $successes > 0)) {
                break;
            }
            $successes += (int) $successful;
            $failures += (int) ! $successful;
        }

        return new RecommendationEligibility($exerciseId, $successes, $failures, $sinceReplacement,
            $lastRejectedAt === null ? max(4, $sinceRejection) : $sinceRejection, $currentlySuccessful);
    }

    private function successful(ExercisePerformanceSnapshot $exercise): bool
    {
        if ($exercise->status === ExerciseCompletionStatus::Skipped) {
            return false;
        }
        $actual = $exercise->actualSets->all();
        foreach ($exercise->plannedSets as $index => $planned) {
            $performed = $actual[$index] ?? null;
            if ($performed === null || $performed->repetitions->value < $planned->repetitions->value
                || $performed->workingWeight->grams < $planned->workingWeight->grams) {
                return false;
            }
        }

        return true;
    }
}
