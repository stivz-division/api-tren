<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use UnexpectedValueException;

final class WorkoutAIResultCodec
{
    public const int VERSION = 1;

    /** @return array<string, mixed> */
    public function encode(WorkoutAIResult $result): array
    {
        return [
            'analysis_id' => $result->conclusion->analysisId->value,
            'workout_session_id' => $result->conclusion->workoutSessionId->value,
            'current_workout' => $result->conclusion->currentWorkout,
            'history' => $result->conclusion->history,
            'evidence' => array_map(static fn (AnalysisEvidenceReference $ref): array => [
                'analysis_id' => $ref->analysisId->value,
                'workout_session_id' => $ref->workoutSessionId->value,
                'exercise_id' => $ref->exerciseId?->value,
            ], $result->conclusion->evidence),
            'model' => $result->model,
            'response_id' => $result->responseId,
            'prompt_version' => $result->promptVersion,
            'schema_version' => $result->schemaVersion,
        ];
    }

    /** Историческое заключение читается без рекурсивной загрузки контекста исходного анализа.
     * @param  array<string, mixed>  $payload
     */
    public function decodeConclusion(array $payload, int $version, WorkoutAnalysisId $analysisId, WorkoutSessionId $sessionId): HistoricalAIConclusion
    {
        if ($version !== self::VERSION || count($payload) !== 9
            || AnalysisPayload::integer($payload['analysis_id'] ?? null) !== $analysisId->value
            || AnalysisPayload::integer($payload['workout_session_id'] ?? null) !== $sessionId->value
            || trim(AnalysisPayload::string($payload['model'] ?? null)) === ''
            || trim(AnalysisPayload::string($payload['response_id'] ?? null)) === ''
            || AnalysisPayload::integer($payload['prompt_version'] ?? null) < 1
            || AnalysisPayload::integer($payload['schema_version'] ?? null) < 1) {
            throw new UnexpectedValueException('Некорректное историческое заключение ИИ.');
        }
        $evidence = [];
        foreach (AnalysisPayload::list($payload['evidence'] ?? null) as $value) {
            $ref = AnalysisPayload::object($value);
            if (count($ref) !== 3 || ! array_key_exists('exercise_id', $ref)) {
                throw new UnexpectedValueException('Некорректное основание исторического заключения.');
            }
            $evidence[] = new AnalysisEvidenceReference(
                new WorkoutAnalysisId(AnalysisPayload::integer($ref['analysis_id'] ?? null)),
                new WorkoutSessionId(AnalysisPayload::integer($ref['workout_session_id'] ?? null)),
                $ref['exercise_id'] === null ? null : new ExerciseId(AnalysisPayload::integer($ref['exercise_id'])),
            );
        }

        return new HistoricalAIConclusion($analysisId, $sessionId,
            AnalysisPayload::string($payload['current_workout'] ?? null),
            AnalysisPayload::string($payload['history'] ?? null), ...$evidence);
    }

    /** @param array<string, mixed> $payload */
    public function decode(array $payload, int $version, WorkoutAnalysisId $analysisId, AnalysisContextSnapshot $context): WorkoutAIResult
    {
        if ($version !== self::VERSION) {
            throw new UnexpectedValueException('Неподдерживаемая версия формата заключения ИИ.');
        }
        $evidence = [];
        foreach (AnalysisPayload::list($payload['evidence'] ?? null) as $value) {
            $ref = AnalysisPayload::object($value);
            $evidence[] = new AnalysisEvidenceReference(
                new WorkoutAnalysisId(AnalysisPayload::integer($ref['analysis_id'] ?? null)),
                new WorkoutSessionId(AnalysisPayload::integer($ref['workout_session_id'] ?? null)),
                ($ref['exercise_id'] ?? null) === null ? null : new ExerciseId(AnalysisPayload::integer($ref['exercise_id'])),
            );
        }
        $result = new WorkoutAIResult(
            $analysisId, $context,
            AnalysisPayload::string($payload['current_workout'] ?? null),
            AnalysisPayload::string($payload['history'] ?? null),
            AnalysisPayload::string($payload['model'] ?? null),
            AnalysisPayload::string($payload['response_id'] ?? null),
            AnalysisPayload::integer($payload['prompt_version'] ?? null),
            AnalysisPayload::integer($payload['schema_version'] ?? null),
            ...$evidence,
        );
        if (! AnalysisPayload::equals($this->encode($result), $payload)) {
            throw new UnexpectedValueException('Некорректное сохранённое заключение ИИ.');
        }

        return $result;
    }
}
