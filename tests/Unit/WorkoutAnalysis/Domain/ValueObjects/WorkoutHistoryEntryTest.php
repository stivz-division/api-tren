<?php

use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('distinguishes missing recommendations from a completed empty result', function () {
    $result = Fixture::result();
    $missing = new WorkoutHistoryEntry($result);
    $empty = new WorkoutHistoryEntry($result, recommendations: new HistoricalRecommendationResult(new WorkoutAnalysisId(9), $result->snapshot->workoutSessionId));

    expect($missing->conclusion)->toBeNull();
    expect($missing->recommendations)->toBeNull();
    expect($empty->recommendations?->recommendations)->toBe([]);
});

it('rejects a conclusion from another workout', function () {
    $conclusion = new HistoricalAIConclusion(new WorkoutAnalysisId(9), new WorkoutSessionId(99), 'План выполнен.', 'Истории нет.');

    expect(fn () => new WorkoutHistoryEntry(Fixture::result(), $conclusion))->toThrow(InvalidAnalysisContext::class);
});

it('rejects recommendations from another workout', function () {
    $recommendations = new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(99));

    expect(fn () => new WorkoutHistoryEntry(Fixture::result(), recommendations: $recommendations))->toThrow(InvalidAnalysisContext::class);
});

it('rejects supplements from different source analyses of the same workout', function () {
    $sessionId = new WorkoutSessionId(51);
    $conclusion = new HistoricalAIConclusion(new WorkoutAnalysisId(9), $sessionId, 'План выполнен.', 'Истории нет.');
    $recommendations = new HistoricalRecommendationResult(new WorkoutAnalysisId(10), $sessionId);

    expect(fn () => new WorkoutHistoryEntry(Fixture::result(), $conclusion, $recommendations))->toThrow(InvalidAnalysisContext::class);
});
