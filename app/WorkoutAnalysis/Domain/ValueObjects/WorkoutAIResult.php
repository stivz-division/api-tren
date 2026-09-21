<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class WorkoutAIResult
{
    public private(set) HistoricalAIConclusion $conclusion;

    public function __construct(
        WorkoutAnalysisId $analysisId,
        public private(set) AnalysisContextSnapshot $context,
        string $currentWorkout,
        string $history,
        public private(set) string $model,
        public private(set) string $responseId,
        public private(set) int $promptVersion,
        public private(set) int $schemaVersion,
        AnalysisEvidenceReference ...$evidence,
    ) {
        if (trim($model) === '' || trim($responseId) === '' || $promptVersion < 1 || $schemaVersion < 1) {
            throw new InvalidArgumentException('Заключение должно содержать сведения о модели, ответе и версиях контракта.');
        }
        $snapshots = [$context->currentWorkout->snapshot];
        foreach ([$context->sameProgram, $context->otherPrograms] as $window) {
            foreach ($window as $entry) {
                $snapshots[] = $entry->deviations->snapshot;
            }
        }
        $available = [];
        foreach ($snapshots as $snapshot) {
            $available[$snapshot->workoutSessionId->value] = array_map(
                static fn (ExercisePerformanceSnapshot $exercise): int => $exercise->exerciseId->value,
                $snapshot->exercises->all(),
            );
        }
        foreach ($evidence as $reference) {
            $exercises = $available[$reference->workoutSessionId->value] ?? null;
            if ($exercises === null || ($reference->exerciseId !== null && ! in_array($reference->exerciseId->value, $exercises, true))) {
                throw new InvalidArgumentException('Основание заключения отсутствует в зафиксированном контексте.');
            }
        }
        $this->conclusion = new HistoricalAIConclusion($analysisId, $context->currentWorkout->snapshot->workoutSessionId, $currentWorkout, $history, ...$evidence);
    }
}
