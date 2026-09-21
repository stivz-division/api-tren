<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('binds both sections and provenance to the exact captured context', function (): void {
    $context = new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-21T12:00:00Z'));
    $result = new WorkoutAIResult(new WorkoutAnalysisId(1), $context, 'План выполнен.', 'Истории пока нет.', 'test-model', 'resp_1', 1, 1,
        new AnalysisEvidenceReference(new WorkoutAnalysisId(1), new WorkoutSessionId(51), new ExerciseId(10)));

    expect($result->context)->toBe($context);
    expect($result->conclusion->workoutSessionId->value)->toBe(51);
    expect($result->conclusion->currentWorkout)->toBe('План выполнен.');
});

it('rejects references outside the captured sessions or their exercises', function (int $sessionId, ?int $exerciseId): void {
    $context = new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-21T12:00:00Z'));

    expect(fn () => new WorkoutAIResult(new WorkoutAnalysisId(1), $context, 'План выполнен.', 'Истории нет.', 'test-model', 'resp_1', 1, 1,
        new AnalysisEvidenceReference(new WorkoutAnalysisId(1), new WorkoutSessionId($sessionId), $exerciseId === null ? null : new ExerciseId($exerciseId))))
        ->toThrow(InvalidArgumentException::class);
})->with([[999, null], [51, 999]]);
