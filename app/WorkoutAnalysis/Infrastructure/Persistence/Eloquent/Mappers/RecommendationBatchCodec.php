<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationProposal;
use App\WorkoutAnalysis\Domain\ValueObjects\Repetitions;
use App\WorkoutAnalysis\Domain\ValueObjects\SetPosition;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkingWeight;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;
use UnexpectedValueException;

final class RecommendationBatchCodec
{
    public const int VERSION = 1;

    /** @return array<string, mixed> */
    public function encode(RecommendationBatch $batch): array
    {
        return [
            'proposals' => array_map(static fn (RecommendationProposal $proposal): array => [
                'exercise_id' => $proposal->exerciseId,
                'change_type' => $proposal->changeType,
                'replacement_exercise_id' => $proposal->replacementExerciseId,
                'proposed_sets' => array_map(static fn (WorkoutSetSnapshot $set): array => [
                    'position' => $set->position->value, 'repetitions' => $set->repetitions->value, 'working_weight_grams' => $set->workingWeight->grams,
                ], $proposal->proposedSets->all()),
                'rationale' => $proposal->rationale,
                'evidence' => array_map(static fn (AnalysisEvidenceReference $ref): array => [
                    'analysis_id' => $ref->analysisId->value, 'workout_session_id' => $ref->workoutSessionId->value, 'exercise_id' => $ref->exerciseId?->value,
                ], $proposal->evidence),
            ], $batch->proposals),
            'no_change_reason' => $batch->noChangeReason,
            'rejected_reasons' => $batch->rejectedReasons,
            'model' => $batch->model,
            'response_id' => $batch->responseId,
            'prompt_version' => $batch->promptVersion,
            'schema_version' => $batch->schemaVersion,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function decode(array $payload, int $version): RecommendationBatch
    {
        if ($version !== self::VERSION) {
            throw new UnexpectedValueException('Неподдерживаемая версия рекомендаций.');
        }
        $proposals = [];
        foreach (AnalysisPayload::list($payload['proposals'] ?? null) as $value) {
            $proposal = AnalysisPayload::object($value);
            $sets = [];
            foreach (AnalysisPayload::list($proposal['proposed_sets'] ?? null) as $item) {
                $set = AnalysisPayload::object($item);
                $sets[] = new WorkoutSetSnapshot(new SetPosition(AnalysisPayload::integer($set['position'] ?? null)), new Repetitions(AnalysisPayload::integer($set['repetitions'] ?? null)), new WorkingWeight(AnalysisPayload::integer($set['working_weight_grams'] ?? null)));
            }
            $evidence = [];
            foreach (AnalysisPayload::list($proposal['evidence'] ?? null) as $item) {
                $ref = AnalysisPayload::object($item);
                $evidence[] = new AnalysisEvidenceReference(new WorkoutAnalysisId(AnalysisPayload::integer($ref['analysis_id'] ?? null)), new WorkoutSessionId(AnalysisPayload::integer($ref['workout_session_id'] ?? null)), ($ref['exercise_id'] ?? null) === null ? null : new ExerciseId(AnalysisPayload::integer($ref['exercise_id'])));
            }
            $proposals[] = new RecommendationProposal(
                AnalysisPayload::integer($proposal['exercise_id'] ?? null), AnalysisPayload::string($proposal['change_type'] ?? null),
                ($proposal['replacement_exercise_id'] ?? null) === null ? null : AnalysisPayload::integer($proposal['replacement_exercise_id']),
                new SetSnapshotCollection(...$sets), AnalysisPayload::string($proposal['rationale'] ?? null), ...$evidence,
            );
        }
        $batch = new RecommendationBatch($proposals,
            ($payload['no_change_reason'] ?? null) === null ? null : AnalysisPayload::string($payload['no_change_reason']),
            array_map(AnalysisPayload::string(...), AnalysisPayload::list($payload['rejected_reasons'] ?? null)),
            ($payload['model'] ?? null) === null ? null : AnalysisPayload::string($payload['model']),
            ($payload['response_id'] ?? null) === null ? null : AnalysisPayload::string($payload['response_id']),
            AnalysisPayload::integer($payload['prompt_version'] ?? null), AnalysisPayload::integer($payload['schema_version'] ?? null),
        );
        if (! AnalysisPayload::equals($this->encode($batch), $payload)) {
            throw new UnexpectedValueException('Некорректные сохранённые рекомендации.');
        }

        return $batch;
    }
}
