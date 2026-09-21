<?php

use App\WorkoutAnalysis\Application\DTO\HistoricalWorkoutData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryData;
use App\WorkoutAnalysis\Application\Exceptions\CompletedWorkoutNotFound;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutNotCompleted;
use App\WorkoutAnalysis\Application\Factories\AnalysisContextSnapshotFactory;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Policies\AnalysisHistoryPolicy;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

$factory = static fn (): AnalysisContextSnapshotFactory => new AnalysisContextSnapshotFactory(new CompletedWorkoutSnapshotFactory, new WorkoutDeviationCalculator);
$capturedAt = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-17 12:00:00+00:00');

it('calculates missing historical deviations from their own original plan and actual sets', function () use ($factory, $capturedAt) {
    $historical = Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00', exercise: Fixture::exercise([[8, 40_000]], [[6, 40_000]]));
    $data = new WorkoutHistoryData([new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data($historical->snapshot))], []);

    $context = $factory()->create(Fixture::result(), $data, new AnalysisHistoryPolicy, $capturedAt());

    expect($context->sameProgram->all()[0]->deviations->repetitions->difference)->toBe(-2);
    expect($context->sameProgram->all()[0]->deviations->volume->actual)->toBe(240_000);
    expect($context->sameProgram->all()[0]->conclusion)->toBeNull();
    expect($context->sameProgram->all()[0]->recommendations)->toBeNull();
});

it('reuses matching saved deviations and available historical supplements', function () use ($factory, $capturedAt) {
    $historical = Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00');
    $conclusion = new HistoricalAIConclusion(new WorkoutAnalysisId(9), new WorkoutSessionId(50), 'План выполнен.', 'Истории нет.');
    $recommendations = new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50));
    $data = new WorkoutHistoryData([new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data($historical->snapshot), $historical, $conclusion, $recommendations)], []);

    $entry = $factory()->create(Fixture::result(), $data, new AnalysisHistoryPolicy, $capturedAt())->sameProgram->all()[0];

    expect($entry->deviations)->toBe($historical);
    expect($entry->conclusion)->toBe($conclusion);
    expect($entry->recommendations)->toBe($recommendations);
    expect($entry->recommendations?->recommendations)->toBe([]);
});

it('rejects saved deviations that do not match the supplied historical snapshot', function () use ($factory, $capturedAt) {
    $historical = Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00');
    $wrong = Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00', exercise: Fixture::exercise(actual: [[8, 50_000]]));
    $data = new WorkoutHistoryData([new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data($historical->snapshot), $wrong)], []);

    expect(fn () => $factory()->create(Fixture::result(), $data, new AnalysisHistoryPolicy, $capturedAt()))->toThrow(InvalidAnalysisContext::class);
});

it('rejects a noncompleted historical source even when cached deviations are supplied', function (string $status) use ($factory, $capturedAt) {
    $historical = Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00');
    $data = new WorkoutHistoryData([new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data($historical->snapshot, $status), $historical)], []);

    expect(fn () => $factory()->create(Fixture::result(), $data, new AnalysisHistoryPolicy, $capturedAt()))->toThrow(WorkoutNotCompleted::class);
})->with(['in_progress', 'cancelled']);

it('rejects historical data from another user', function () use ($factory, $capturedAt) {
    $historical = Fixture::result(sessionId: 50, userId: 8, completedAt: '2026-09-14 12:00:00+00:00');
    $data = new WorkoutHistoryData([new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data($historical->snapshot))], []);

    expect(fn () => $factory()->create(Fixture::result(), $data, new AnalysisHistoryPolicy, $capturedAt()))->toThrow(CompletedWorkoutNotFound::class);
});

it('rejects oversized provider windows instead of silently truncating them', function () use ($factory, $capturedAt) {
    $data = new WorkoutHistoryData(array_map(static fn (int $id): HistoricalWorkoutData => new HistoricalWorkoutData(
        InMemoryAnalysisEnvironment::data(Fixture::result(sessionId: $id, completedAt: '2026-09-14 12:00:00+00:00')->snapshot),
    ), [48, 49]), []);

    expect(fn () => $factory()->create(Fixture::result(), $data, new AnalysisHistoryPolicy(1, 20), $capturedAt()))->toThrow(InvalidAnalysisContext::class);
});

it('keeps every selected record and normalizes provider order chronologically', function () use ($factory, $capturedAt) {
    $same = new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data(Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00')->snapshot));
    $other = array_map(static fn (int $id): HistoricalWorkoutData => new HistoricalWorkoutData(
        InMemoryAnalysisEnvironment::data(Fixture::result(sessionId: $id, programId: $id, completedAt: '2026-09-14 12:00:00+00:00')->snapshot),
    ), [49, 48]);

    $context = $factory()->create(Fixture::result(), new WorkoutHistoryData([$same], $other), new AnalysisHistoryPolicy(1, 2), $capturedAt());

    expect($context->sameProgram)->toHaveCount(1);
    expect(array_map(static fn ($entry): int => $entry->deviations->snapshot->workoutSessionId->value, $context->otherPrograms->all()))->toBe([48, 49]);
    expect($context->sameProgram->limit)->toBe(1);
    expect($context->otherPrograms->limit)->toBe(2);
});
