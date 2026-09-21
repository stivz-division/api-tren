<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

$recommendation = static fn (int $id = 1, ?DateTimeImmutable $appliedAt = null, int $exerciseId = 10): HistoricalRecommendation => new HistoricalRecommendation(
    id: $id,
    exerciseId: new ExerciseId($exerciseId),
    originalSets: Fixture::sets([[10, 50_000]]),
    changeType: 'load_adjustment',
    replacementExerciseId: null,
    proposedSets: Fixture::sets([[10, 52_500]]),
    rationale: 'План выполнен три раза.',
    status: 'applied',
    appliedAt: $appliedAt ?? new DateTimeImmutable('2026-09-14 13:00:00+00:00'),
    evidence: new AnalysisEvidenceReference(new WorkoutAnalysisId(9), new WorkoutSessionId(50), new ExerciseId(10)),
);

it('preserves application to the plan without requiring a subsequent workout', function () use ($recommendation) {
    $proposal = $recommendation();
    $result = new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $proposal);

    expect($result->recommendations)->toBe([$proposal]);
    expect($result->recommendations[0]->appliedAt)->toEqual(new DateTimeImmutable('2026-09-14 13:00:00+00:00'));
    expect($result->recommendations[0]->proposedSets->all()[0]->workingWeight->grams)->toBe(52_500);
});

it('rejects duplicate recommendation ids', function () use ($recommendation) {
    expect(fn () => new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $recommendation(), $recommendation(exerciseId: 11)))
        ->toThrow(InvalidAnalysisContext::class);
});

it('rejects multiple recommendations targeting the same exercise', function () use ($recommendation) {
    expect(fn () => new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $recommendation(), $recommendation(id: 2)))
        ->toThrow(InvalidAnalysisContext::class);
});

it('keeps independent recommendations targeting different exercises', function () use ($recommendation) {
    $first = $recommendation();
    $second = $recommendation(id: 2, exerciseId: 11);

    $result = new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $first, $second);

    expect($result->recommendations)->toBe([$first, $second]);
});

it('rejects recommendation evidence from another analysis', function () use ($recommendation) {
    expect(fn () => new HistoricalRecommendationResult(new WorkoutAnalysisId(10), new WorkoutSessionId(50), $recommendation()))
        ->toThrow(InvalidAnalysisContext::class);
});

it('accepts application after the analyzed workout but before context capture', function () use ($recommendation) {
    $result = new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $recommendation(appliedAt: new DateTimeImmutable('2026-09-15 12:01:00+00:00')));
    $history = new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00'), recommendations: $result);

    $context = new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow(20, $history), new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15 12:02:00+00:00'));

    expect($context->sameProgram->all()[0]->recommendations)->toBe($result);
});

it('rejects recommendation actions dated after context capture', function () use ($recommendation) {
    $result = new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $recommendation(appliedAt: new DateTimeImmutable('2026-09-15 12:03:00+00:00')));
    $history = new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00'), recommendations: $result);

    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow(20, $history), new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15 12:02:00+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
});
