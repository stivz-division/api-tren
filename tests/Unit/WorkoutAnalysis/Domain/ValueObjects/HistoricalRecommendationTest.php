<?php

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('preserves a replacement and historical status without inventing a usage outcome', function () {
    $snapshot = new HistoricalRecommendation(
        id: 1, exerciseId: new ExerciseId(10), originalSets: Fixture::sets([[10, 50_000]]),
        changeType: 'exercise_replacement', replacementExerciseId: new ExerciseId(20), proposedSets: Fixture::sets([[12, 25_000]]),
        rationale: 'Предложена замена.', status: 'rejected', rejectedAt: new DateTimeImmutable('2026-09-15 12:00:00+00:00'),
    );

    expect($snapshot->replacementExerciseId?->value)->toBe(20);
    expect($snapshot->status)->toBe('rejected');
    expect($snapshot->appliedAt)->toBeNull();
    expect($snapshot->originalSets->all()[0]->workingWeight->grams)->toBe(50_000);
});

it('rejects missing recommendation identity or descriptive fields', function (int $id, string $type, string $reason, string $status) {
    expect(fn () => new HistoricalRecommendation($id, new ExerciseId(10), Fixture::sets([[10, 50_000]]), $type, null, Fixture::sets([[10, 52_500]]), $reason, $status))
        ->toThrow(InvalidAnalysisContext::class);
})->with([
    'zero id' => [0, 'load_adjustment', 'Прогрессия.', 'proposed'],
    'negative id' => [-1, 'load_adjustment', 'Прогрессия.', 'proposed'],
    'missing type' => [1, ' ', 'Прогрессия.', 'proposed'],
    'missing reason' => [1, 'load_adjustment', "\n", 'proposed'],
    'missing status' => [1, 'load_adjustment', 'Прогрессия.', ''],
]);

it('rejects an empty original or proposed plan', function (bool $emptyOriginal) {
    $sets = Fixture::sets([[10, 50_000]]);
    $empty = new SetSnapshotCollection;

    expect(fn () => new HistoricalRecommendation(1, new ExerciseId(10), $emptyOriginal ? $empty : $sets, 'load_adjustment', null, $emptyOriginal ? $sets : $empty, 'Прогрессия.', 'proposed'))
        ->toThrow(InvalidAnalysisContext::class);
})->with(['original plan' => true, 'proposed plan' => false]);

it('rejects replacement with the same exercise', function () {
    expect(fn () => new HistoricalRecommendation(1, new ExerciseId(10), Fixture::sets([[10, 50_000]]), 'exercise_replacement', new ExerciseId(10), Fixture::sets([[10, 50_000]]), 'Замена.', 'proposed'))
        ->toThrow(InvalidAnalysisContext::class);
});

it('keeps evidence frozen when a caller replaces a referenced array element', function () {
    $reference = new AnalysisEvidenceReference(new WorkoutAnalysisId(9), new WorkoutSessionId(50));
    $evidence = [&$reference];
    $snapshot = new HistoricalRecommendation(
        1, new ExerciseId(10), Fixture::sets([[10, 50_000]]), 'load_adjustment', null,
        Fixture::sets([[10, 52_500]]), 'Прогрессия.', 'proposed', null, null, null, ...$evidence,
    );

    $reference = new AnalysisEvidenceReference(new WorkoutAnalysisId(999), new WorkoutSessionId(99));

    expect($snapshot->evidence[0]->analysisId->value)->toBe(9);
    expect($snapshot->evidence[0]->workoutSessionId->value)->toBe(50);
});
