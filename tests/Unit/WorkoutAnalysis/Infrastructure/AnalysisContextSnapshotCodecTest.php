<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\AnalysisContextSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\AnalysisPayload;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\CompletedWorkoutSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutDeviationResultCodec;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

it('round trips history and distinguishes missing recommendations from a completed empty result', function (): void {
    $snapshotCodec = new CompletedWorkoutSnapshotCodec;
    $codec = new AnalysisContextSnapshotCodec($snapshotCodec, new WorkoutDeviationResultCodec($snapshotCodec));
    $past = WorkoutAnalysisFixture::result(sessionId: 50, completedAt: '2026-09-14T12:00:00Z');
    $empty = new HistoricalRecommendationResult(new WorkoutAnalysisId(100), $past->snapshot->workoutSessionId);
    $context = new AnalysisContextSnapshot(WorkoutAnalysisFixture::result(), new WorkoutHistoryWindow(3, new WorkoutHistoryEntry($past, recommendations: $empty)), new WorkoutHistoryWindow(7), new DateTimeImmutable('2026-09-21T19:00:00.123456+07:00'));

    $payload = json_decode(json_encode($codec->encode($context), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $restored = $codec->decode(AnalysisPayload::object($payload), 1, $context->currentWorkout);

    expect($restored)->toEqual($context);
    expect($restored->capturedAt->format('Y-m-d H:i:s.uP'))->toBe('2026-09-21 12:00:00.123456+00:00');
});

$richContext = static function (): AnalysisContextSnapshot {
    $past = WorkoutAnalysisFixture::result(sessionId: 50, completedAt: '2026-09-14T12:00:00Z');
    $analysisId = new WorkoutAnalysisId(100);
    $sessionId = $past->snapshot->workoutSessionId;
    $reference = new AnalysisEvidenceReference($analysisId, new WorkoutSessionId(49), new ExerciseId(10));
    $conclusion = new HistoricalAIConclusion($analysisId, $sessionId, 'Снижение объёма.', 'Возможна усталость.', $reference, new AnalysisEvidenceReference($analysisId, $sessionId));
    $recommendation = new HistoricalRecommendation(
        1, new ExerciseId(10), WorkoutAnalysisFixture::sets([[10, 50000]]), 'replacement', new ExerciseId(20),
        WorkoutAnalysisFixture::sets([[12, 45000], [10, 45000]]), 'Изменить нагрузку.', 'applied',
        new DateTimeImmutable('2026-09-16T12:00:00.654321Z'), null, null, $reference,
    );

    return new AnalysisContextSnapshot(
        WorkoutAnalysisFixture::result(),
        new WorkoutHistoryWindow(3, new WorkoutHistoryEntry($past, $conclusion, new HistoricalRecommendationResult($analysisId, $sessionId, $recommendation))),
        new WorkoutHistoryWindow(7, new WorkoutHistoryEntry(WorkoutAnalysisFixture::result(sessionId: 48, programId: 12, completedAt: '2026-09-13T12:00:00Z'))),
        new DateTimeImmutable('2026-09-21T12:00:00.123456Z'),
    );
};

it('preserves recommendation plans statuses application times and original evidence sources', function () use ($richContext): void {
    $snapshots = new CompletedWorkoutSnapshotCodec;
    $codec = new AnalysisContextSnapshotCodec($snapshots, new WorkoutDeviationResultCodec($snapshots));
    $context = $richContext();
    $payload = AnalysisPayload::object(json_decode(json_encode($codec->encode($context), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

    expect($codec->decode($payload, 1, $context->currentWorkout))->toEqual($context);
});

it('rejects unsupported versions and corrupt context payloads', function (string $corruption) use ($richContext): void {
    $snapshots = new CompletedWorkoutSnapshotCodec;
    $codec = new AnalysisContextSnapshotCodec($snapshots, new WorkoutDeviationResultCodec($snapshots));
    $context = $richContext();
    $payload = $codec->encode($context);
    $version = 1;
    switch ($corruption) {
        case 'version': $version = 999;
            break;
        case 'owner': $payload['current_workout_session_id'] = 999;
            break;
        case 'missing window': unset($payload['same_program']);
            break;
        case 'date': $payload['captured_at'] = '2026-02-30T12:00:00.000000Z';
            break;
        case 'extra field': $payload['usage_confirmed'] = true;
            break;
    }

    expect(fn () => $codec->decode($payload, $version, $context->currentWorkout))->toThrow(UnexpectedValueException::class);
})->with(['version', 'owner', 'missing window', 'date', 'extra field']);
