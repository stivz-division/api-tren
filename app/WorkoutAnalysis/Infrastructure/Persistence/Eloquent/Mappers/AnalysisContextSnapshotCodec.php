<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use DateTimeImmutable;
use UnexpectedValueException;

final readonly class AnalysisContextSnapshotCodec
{
    public const int VERSION = 1;

    public function __construct(
        private CompletedWorkoutSnapshotCodec $snapshotCodec,
        private WorkoutDeviationResultCodec $resultCodec,
    ) {}

    /** @return array<string, mixed> */
    public function encode(AnalysisContextSnapshot $context): array
    {
        return [
            'current_workout_session_id' => $context->currentWorkout->snapshot->workoutSessionId->value,
            'captured_at' => AnalysisPayload::formatDate($context->capturedAt),
            'same_program' => $this->encodeWindow($context->sameProgram),
            'other_programs' => $this->encodeWindow($context->otherPrograms),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function decode(array $payload, int $version, WorkoutDeviationResult $currentWorkout): AnalysisContextSnapshot
    {
        if ($version !== self::VERSION) {
            throw new UnexpectedValueException('Неподдерживаемая версия формата контекста анализа.');
        }
        $context = new AnalysisContextSnapshot(
            $currentWorkout,
            $this->decodeWindow($payload['same_program'] ?? null),
            $this->decodeWindow($payload['other_programs'] ?? null),
            AnalysisPayload::date($payload['captured_at'] ?? null),
        );
        if (! AnalysisPayload::equals($this->encode($context), $payload)) {
            throw new UnexpectedValueException('Некорректный сохранённый контекст анализа.');
        }

        return $context;
    }

    /** @return array<string, mixed> */
    private function encodeWindow(WorkoutHistoryWindow $window): array
    {
        return ['limit' => $window->limit, 'entries' => array_map(fn (WorkoutHistoryEntry $entry): array => [
            'snapshot' => $this->snapshotCodec->encode($entry->deviations->snapshot),
            'snapshot_version' => CompletedWorkoutSnapshotCodec::VERSION,
            'deviations' => $this->resultCodec->encode($entry->deviations),
            'deviations_version' => WorkoutDeviationResultCodec::VERSION,
            'conclusion' => $entry->conclusion === null ? null : [
                'analysis_id' => $entry->conclusion->analysisId->value,
                'workout_session_id' => $entry->conclusion->workoutSessionId->value,
                'current_workout' => $entry->conclusion->currentWorkout,
                'history' => $entry->conclusion->history,
                'evidence' => $this->encodeEvidence($entry->conclusion->evidence),
            ],
            'recommendations' => $entry->recommendations === null ? null : [
                'analysis_id' => $entry->recommendations->analysisId->value,
                'workout_session_id' => $entry->recommendations->workoutSessionId->value,
                'items' => array_map($this->encodeRecommendation(...), $entry->recommendations->recommendations),
            ],
        ], $window->all())];
    }

    private function decodeWindow(mixed $payload): WorkoutHistoryWindow
    {
        $window = AnalysisPayload::object($payload);
        $entries = [];
        foreach (AnalysisPayload::list($window['entries'] ?? null) as $item) {
            $entry = AnalysisPayload::object($item);
            $snapshot = $this->snapshotCodec->decode(AnalysisPayload::object($entry['snapshot'] ?? null), AnalysisPayload::integer($entry['snapshot_version'] ?? null));
            $entries[] = new WorkoutHistoryEntry(
                $this->resultCodec->decode(AnalysisPayload::object($entry['deviations'] ?? null), AnalysisPayload::integer($entry['deviations_version'] ?? null), $snapshot),
                $this->decodeConclusion($entry['conclusion'] ?? null),
                $this->decodeRecommendations($entry['recommendations'] ?? null),
            );
        }

        return new WorkoutHistoryWindow(AnalysisPayload::integer($window['limit'] ?? null), ...$entries);
    }

    private function decodeConclusion(mixed $payload): ?HistoricalAIConclusion
    {
        if ($payload === null) {
            return null;
        }
        $data = AnalysisPayload::object($payload);

        return new HistoricalAIConclusion(
            new WorkoutAnalysisId(AnalysisPayload::integer($data['analysis_id'] ?? null)),
            new WorkoutSessionId(AnalysisPayload::integer($data['workout_session_id'] ?? null)),
            AnalysisPayload::string($data['current_workout'] ?? null),
            AnalysisPayload::string($data['history'] ?? null),
            ...$this->decodeEvidence($data['evidence'] ?? null),
        );
    }

    private function decodeRecommendations(mixed $payload): ?HistoricalRecommendationResult
    {
        if ($payload === null) {
            return null;
        }
        $data = AnalysisPayload::object($payload);
        $recommendations = [];
        foreach (AnalysisPayload::list($data['items'] ?? null) as $item) {
            $recommendations[] = $this->decodeRecommendation(AnalysisPayload::object($item));
        }

        return new HistoricalRecommendationResult(
            new WorkoutAnalysisId(AnalysisPayload::integer($data['analysis_id'] ?? null)),
            new WorkoutSessionId(AnalysisPayload::integer($data['workout_session_id'] ?? null)),
            ...$recommendations,
        );
    }

    /** @return array<string, mixed> */
    private function encodeRecommendation(HistoricalRecommendation $recommendation): array
    {
        return [
            'id' => $recommendation->id,
            'exercise_id' => $recommendation->exerciseId->value,
            'original_sets' => array_map($this->snapshotCodec->encodeSet(...), $recommendation->originalSets->all()),
            'change_type' => $recommendation->changeType,
            'replacement_exercise_id' => $recommendation->replacementExerciseId?->value,
            'proposed_sets' => array_map($this->snapshotCodec->encodeSet(...), $recommendation->proposedSets->all()),
            'rationale' => $recommendation->rationale,
            'status' => $recommendation->status,
            'applied_at' => $recommendation->appliedAt === null ? null : AnalysisPayload::formatDate($recommendation->appliedAt),
            'rejected_at' => $recommendation->rejectedAt === null ? null : AnalysisPayload::formatDate($recommendation->rejectedAt),
            'expired_at' => $recommendation->expiredAt === null ? null : AnalysisPayload::formatDate($recommendation->expiredAt),
            'evidence' => $this->encodeEvidence($recommendation->evidence),
        ];
    }

    /** @param array<string, mixed> $data */
    private function decodeRecommendation(array $data): HistoricalRecommendation
    {
        return new HistoricalRecommendation(
            AnalysisPayload::integer($data['id'] ?? null),
            new ExerciseId(AnalysisPayload::integer($data['exercise_id'] ?? null)),
            $this->snapshotCodec->decodeSets($data['original_sets'] ?? null),
            AnalysisPayload::string($data['change_type'] ?? null),
            ($data['replacement_exercise_id'] ?? null) === null ? null : new ExerciseId(AnalysisPayload::integer($data['replacement_exercise_id'])),
            $this->snapshotCodec->decodeSets($data['proposed_sets'] ?? null),
            AnalysisPayload::string($data['rationale'] ?? null),
            AnalysisPayload::string($data['status'] ?? null),
            $this->nullableDate($data['applied_at'] ?? null),
            $this->nullableDate($data['rejected_at'] ?? null),
            $this->nullableDate($data['expired_at'] ?? null),
            ...$this->decodeEvidence($data['evidence'] ?? null),
        );
    }

    /**
     * @param  list<AnalysisEvidenceReference>  $references
     * @return list<array{analysis_id: int, workout_session_id: int, exercise_id: int|null}>
     */
    private function encodeEvidence(array $references): array
    {
        return array_map(static fn (AnalysisEvidenceReference $reference): array => [
            'analysis_id' => $reference->analysisId->value,
            'workout_session_id' => $reference->workoutSessionId->value,
            'exercise_id' => $reference->exerciseId?->value,
        ], $references);
    }

    /** @return list<AnalysisEvidenceReference> */
    private function decodeEvidence(mixed $payload): array
    {
        $references = [];
        foreach (AnalysisPayload::list($payload) as $item) {
            $data = AnalysisPayload::object($item);
            $references[] = new AnalysisEvidenceReference(
                new WorkoutAnalysisId(AnalysisPayload::integer($data['analysis_id'] ?? null)),
                new WorkoutSessionId(AnalysisPayload::integer($data['workout_session_id'] ?? null)),
                ($data['exercise_id'] ?? null) === null ? null : new ExerciseId(AnalysisPayload::integer($data['exercise_id'])),
            );
        }

        return $references;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : AnalysisPayload::date($value);
    }
}
