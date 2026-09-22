<?php

namespace App\WorkoutAnalysis\Domain\Services;

use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationEligibility;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationProposal;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;

/** @phpstan-type ExerciseContext array{exercise_id:int, revision?:int, sets:list<array{position:int,repetitions:int,working_weight_grams:int}>, successes:int, failures:int, completed_since_replacement:int, completed_since_rejection:int, currently_successful:bool} */
final class RecommendationAdmissionPolicy
{
    public function __construct(private readonly RecommendationCounterCalculator $counters = new RecommendationCounterCalculator) {}

    /** @param array{program_id:int, exercises:list<array{exercise_id:int, revision?:int, sets:list<array{position:int,repetitions:int,working_weight_grams:int}>, successes:int, failures:int, completed_since_replacement:int, completed_since_rejection:int, currently_successful:bool}>, catalog:list<array{id:int,name:string}>} $programContext */
    public function admit(RecommendationBatch $batch, WorkoutAIResult $analysis, array $programContext): RecommendationBatch
    {
        $sources = [];
        foreach ($programContext['exercises'] as $exercise) {
            $sources[$exercise['exercise_id']] = $exercise;
        }
        $counts = [];
        $targets = [];
        foreach ($batch->proposals as $proposal) {
            $counts[$proposal->exerciseId] = ($counts[$proposal->exerciseId] ?? 0) + 1;
            if ($proposal->replacementExerciseId !== null) {
                $targets[$proposal->replacementExerciseId] = ($targets[$proposal->replacementExerciseId] ?? 0) + 1;
            }
        }
        $catalog = array_column($programContext['catalog'], 'id');
        $accepted = [];
        $rejected = $batch->rejectedReasons;
        foreach ($batch->proposals as $proposal) {
            $source = $sources[$proposal->exerciseId] ?? null;
            $reason = null;
            if ($source === null || $programContext['program_id'] !== $analysis->context->currentWorkout->snapshot->trainingProgramId->value) {
                $reason = 'source_not_in_program';
            } elseif ($counts[$proposal->exerciseId] !== 1) {
                $reason = 'conflicting_proposals';
            } elseif (! $this->validEvidence($proposal, $analysis)) {
                $reason = 'invalid_evidence';
            } else {
                if (! in_array($proposal->changeType, $this->allowedChangeTypes($analysis, $source), true)) {
                    $reason = 'eligibility_not_met';
                } elseif ($proposal->replacementExerciseId !== null &&
                    (! in_array($proposal->replacementExerciseId, $catalog, true)
                    || isset($sources[$proposal->replacementExerciseId])
                    || $targets[$proposal->replacementExerciseId] !== 1)) {
                    $reason = 'replacement_conflict';
                } elseif ($proposal->replacementExerciseId === null && $this->unchanged($proposal, $source['sets'])) {
                    $reason = 'unchanged_plan';
                }
            }
            if ($reason !== null) {
                $rejected[] = $proposal->exerciseId.':'.$reason;
            } else {
                $accepted[] = $proposal;
            }
        }

        return new RecommendationBatch($accepted, $batch->noChangeReason, $rejected, $batch->model,
            $batch->responseId, $batch->promptVersion, $batch->schemaVersion);
    }

    /**
     * @param  ExerciseContext  $source
     * @return list<string>
     */
    public function allowedChangeTypes(WorkoutAIResult $analysis, array $source): array
    {
        $eligibility = new RecommendationEligibility($source['exercise_id'], $source['successes'], $source['failures'],
            $source['completed_since_replacement'], $source['completed_since_rejection'], $source['currently_successful']);
        $currentEligibility = null;
        $snapshot = $analysis->context->currentWorkout->snapshot;
        foreach ($snapshot->exercises as $exercise) {
            if ($exercise->exerciseId->value !== $source['exercise_id'] || $exercise->status === ExerciseCompletionStatus::Skipped) {
                continue;
            }
            $plannedSets = [];
            foreach ($exercise->plannedSets as $set) {
                $plannedSets[] = ['position' => $set->position->value, 'repetitions' => $set->repetitions->value,
                    'working_weight_grams' => $set->workingWeight->grams];
            }
            if ($plannedSets === $source['sets']) {
                $currentEligibility = $this->counters->calculate($source['exercise_id'], $exercise->plannedSets, [$snapshot]);
            }
            break;
        }

        $allowed = [];
        foreach (['progression', 'adjustment', 'replacement'] as $type) {
            if ($eligibility->allows($type) && ($type === 'replacement' || $currentEligibility?->allows($type) === true)) {
                $allowed[] = $type;
            }
        }

        return $allowed;
    }

    public function validEvidence(RecommendationProposal $proposal, WorkoutAIResult $analysis): bool
    {
        $snapshots = [$analysis->context->currentWorkout->snapshot];
        foreach ([$analysis->context->sameProgram, $analysis->context->otherPrograms] as $window) {
            foreach ($window as $entry) {
                $snapshots[] = $entry->deviations->snapshot;
            }
        }
        foreach ($proposal->evidence as $reference) {
            if ($reference->analysisId != $analysis->conclusion->analysisId) {
                return false;
            }
            $found = false;
            foreach ($snapshots as $snapshot) {
                if ($snapshot->workoutSessionId != $reference->workoutSessionId) {
                    continue;
                }
                if ($reference->exerciseId === null) {
                    $found = true;
                    break;
                }
                foreach ($snapshot->exercises as $exercise) {
                    if ($exercise->exerciseId == $reference->exerciseId) {
                        $found = true;
                        break;
                    }
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{position:int,repetitions:int,working_weight_grams:int}> $sets */
    private function unchanged(RecommendationProposal $proposal, array $sets): bool
    {
        $proposed = [];
        foreach ($proposal->proposedSets as $set) {
            $proposed[] = ['position' => $set->position->value, 'repetitions' => $set->repetitions->value,
                'working_weight_grams' => $set->workingWeight->grams];
        }

        return $sets === $proposed;
    }
}
