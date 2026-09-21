<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('accepts a first workout with explicitly empty history windows', function () {
    $current = Fixture::result();

    $context = new AnalysisContextSnapshot($current, new WorkoutHistoryWindow, new WorkoutHistoryWindow, $current->snapshot->completedAt);

    expect($context->currentWorkout)->toBe($current);
    expect($context->sameProgram)->toHaveCount(0);
    expect($context->otherPrograms)->toHaveCount(0);
});

it('keeps each historical workout own plan and deviations', function () {
    $previous = Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00', exercise: Fixture::exercise(planned: [[8, 40_000]], actual: [[6, 40_000]]));
    $current = Fixture::result();

    $context = new AnalysisContextSnapshot($current, new WorkoutHistoryWindow(20, new WorkoutHistoryEntry($previous)), new WorkoutHistoryWindow, $current->snapshot->completedAt);

    expect($context->sameProgram->all()[0]->deviations)->toBe($previous);
    expect($context->sameProgram->all()[0]->deviations->repetitions->difference)->toBe(-2);
    expect($context->currentWorkout->snapshot->exercises->all()[0]->plannedSets->all()[0]->workingWeight->grams)->toBe(50_000);
});

it('rejects history from another user', function () {
    $entry = new WorkoutHistoryEntry(Fixture::result(sessionId: 50, userId: 8, completedAt: '2026-09-14 12:00:00+00:00'));

    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow(20, $entry), new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15 12:00:00+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
});

it('rejects a workout in the wrong program window', function (int $programId, bool $sameProgram) {
    $window = new WorkoutHistoryWindow(20, new WorkoutHistoryEntry(Fixture::result(sessionId: 50, programId: $programId, completedAt: '2026-09-14 12:00:00+00:00')));

    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), $sameProgram ? $window : new WorkoutHistoryWindow, $sameProgram ? new WorkoutHistoryWindow : $window, new DateTimeImmutable('2026-09-15 12:00:00+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
})->with(['other program in same window' => [12, true], 'same program in other window' => [11, false]]);

it('rejects history completed at or after the current workout', function (string $completedAt) {
    $window = new WorkoutHistoryWindow(20, new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: $completedAt)));

    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), $window, new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-16 12:00:00+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
})->with(['equal instant in another zone' => '2026-09-15 15:00:00+03:00', 'one microsecond later' => '2026-09-15 12:00:00.000001+00:00', 'later workout' => '2026-09-16 12:00:00+00:00']);

it('accepts history one microsecond before the current workout', function () {
    $window = new WorkoutHistoryWindow(20, new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: '2026-09-15 12:00:00.000000+00:00')));
    $current = Fixture::result(completedAt: '2026-09-15 12:00:00.000001+00:00');

    $context = new AnalysisContextSnapshot($current, $window, new WorkoutHistoryWindow, $current->snapshot->completedAt);

    expect($context->sameProgram)->toHaveCount(1);
});

it('rejects the current session even if its supplied timestamp differs', function () {
    $window = new WorkoutHistoryWindow(20, new WorkoutHistoryEntry(Fixture::result(completedAt: '2026-09-14 12:00:00+00:00')));

    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), $window, new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15 12:00:00+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
});

it('rejects a session repeated across windows even with conflicting program snapshots', function () {
    $same = new WorkoutHistoryWindow(20, new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00')));
    $other = new WorkoutHistoryWindow(20, new WorkoutHistoryEntry(Fixture::result(sessionId: 50, programId: 12, completedAt: '2026-09-14 12:00:00+00:00')));

    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), $same, $other, new DateTimeImmutable('2026-09-15 12:00:00+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
});

it('rejects capture before the current workout completed', function () {
    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15 11:59:59+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
});

it('keeps other programs in one independent window with its own limit', function () {
    $same = new WorkoutHistoryWindow(1, new WorkoutHistoryEntry(Fixture::result(sessionId: 48, completedAt: '2026-09-14 12:00:00+00:00')));
    $others = new WorkoutHistoryWindow(2,
        new WorkoutHistoryEntry(Fixture::result(sessionId: 49, programId: 12, completedAt: '2026-09-14 13:00:00+00:00')),
        new WorkoutHistoryEntry(Fixture::result(sessionId: 50, programId: 13, completedAt: '2026-09-14 14:00:00+00:00')),
    );

    $context = new AnalysisContextSnapshot(Fixture::result(), $same, $others, new DateTimeImmutable('2026-09-15 12:00:00+00:00'));

    expect($context->sameProgram)->toHaveCount(1);
    expect($context->otherPrograms)->toHaveCount(2);
    expect($context->otherPrograms->all()[1]->deviations->snapshot->trainingProgramId->value)->toBe(13);
});

it('rejects recommendation action dates outside the history and capture interval', function (?string $appliedAt, ?string $rejectedAt, ?string $expiredAt) {
    $recommendation = new HistoricalRecommendation(
        1, new ExerciseId(10), Fixture::sets([[10, 50_000]]), 'load_adjustment', null,
        Fixture::sets([[10, 52_500]]), 'Прогрессия.', 'historical',
        $appliedAt === null ? null : new DateTimeImmutable($appliedAt),
        $rejectedAt === null ? null : new DateTimeImmutable($rejectedAt),
        $expiredAt === null ? null : new DateTimeImmutable($expiredAt),
    );
    $result = new HistoricalRecommendationResult(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $recommendation);
    $entry = new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00'), recommendations: $result);

    expect(fn () => new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow(20, $entry), new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15 12:00:00+00:00')))
        ->toThrow(InvalidAnalysisContext::class);
})->with([
    'application before source workout' => ['2026-09-14 11:59:59+00:00', null, null],
    'rejection before source workout' => [null, '2026-09-14 11:59:59+00:00', null],
    'expiry before source workout' => [null, null, '2026-09-14 11:59:59+00:00'],
    'rejection after capture' => [null, '2026-09-15 12:00:00.000001+00:00', null],
    'expiry after capture' => [null, null, '2026-09-15 12:00:00.000001+00:00'],
]);
