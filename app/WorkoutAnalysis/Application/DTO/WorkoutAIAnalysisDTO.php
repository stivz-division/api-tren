<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAIAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;

final readonly class WorkoutAIAnalysisDTO
{
    /** @param array{current_workout: string, history: string, evidence: list<array{analysis_id: int, workout_session_id: int, exercise_id: int|null}>}|null $result */
    public function __construct(public string $status, public ?string $failureCode, public ?array $result) {}

    public static function fromDomain(WorkoutAIAnalysis $stage): self
    {
        $conclusion = $stage->result?->conclusion;

        return new self(
            $stage->status()->value,
            $stage->status() === AnalysisStatus::Failed ? $stage->currentAttempt()->failureCode?->value : null,
            $conclusion === null ? null : [
                'current_workout' => $conclusion->currentWorkout,
                'history' => $conclusion->history,
                'evidence' => array_map(static fn (AnalysisEvidenceReference $ref): array => [
                    'analysis_id' => $ref->analysisId->value,
                    'workout_session_id' => $ref->workoutSessionId->value,
                    'exercise_id' => $ref->exerciseId?->value,
                ], $conclusion->evidence),
            ],
        );
    }
}
