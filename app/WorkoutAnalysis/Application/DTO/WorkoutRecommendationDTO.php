<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use DateTimeImmutable;

final readonly class WorkoutRecommendationDTO
{
    /**
     * @param  list<SetSnapshotData>  $originalSets
     * @param  list<SetSnapshotData>  $proposedSets
     * @param  list<array{analysis_id: int, workout_session_id: int, exercise_id: int|null}>  $evidence
     */
    public function __construct(
        public int $id,
        public int $exerciseId,
        public string $changeType,
        public ?int $replacementExerciseId,
        public array $originalSets,
        public array $proposedSets,
        public string $rationale,
        public string $status,
        public ?DateTimeImmutable $appliedAt,
        public ?DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $expiredAt,
        public array $evidence,
    ) {}

    public static function fromDomain(HistoricalRecommendation $value): self
    {
        return new self(
            $value->id, $value->exerciseId->value, $value->changeType, $value->replacementExerciseId?->value,
            array_map(SetSnapshotData::fromDomain(...), $value->originalSets->all()),
            array_map(SetSnapshotData::fromDomain(...), $value->proposedSets->all()),
            $value->rationale, $value->status, $value->appliedAt, $value->rejectedAt, $value->expiredAt,
            array_map(static fn (AnalysisEvidenceReference $ref): array => [
                'analysis_id' => $ref->analysisId->value,
                'workout_session_id' => $ref->workoutSessionId->value,
                'exercise_id' => $ref->exerciseId?->value,
            ], $value->evidence),
        );
    }
}
