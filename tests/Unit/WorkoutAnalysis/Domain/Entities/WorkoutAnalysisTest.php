<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Entities\WorkoutDeviationAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisTransition;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

$now = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-17 12:00:00+00:00');
$analysis = static fn (): WorkoutAnalysis => WorkoutAnalysis::initialize(Fixture::workout(Fixture::exercise()), $now());

it('initializes one pending attempt with an immutable completed snapshot', function () use ($analysis) {
    $aggregate = $analysis();

    expect($aggregate->deviations()->status())->toBe(AnalysisStatus::Pending);
    expect($aggregate->deviations()->currentAttempt()->number)->toBe(1);
    expect($aggregate->deviations()->result)->toBeNull();
    expect($aggregate->deviations()->snapshot->workoutSessionId->value)->toBe(51);
});

it('claims a due attempt once and preserves a completed result', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $result = (new WorkoutDeviationCalculator)->calculate($aggregate->deviations()->snapshot);

    expect($aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes')))->toBeTrue();
    expect($aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes')))->toBeFalse();
    expect($aggregate->completeDeviationAttempt(1, $result, $now()->modify('+1 second')))->toBeTrue();
    expect($aggregate->completeDeviationAttempt(1, $result, $now()->modify('+2 seconds')))->toBeFalse();
    expect($aggregate->retryDeviations($now()->modify('+3 seconds')))->toBeFalse();
    expect($aggregate->deviations()->status())->toBe(AnalysisStatus::Completed);
    expect($aggregate->deviations()->result)->toBe($result);
});

it('keeps failure history and invalidates the previous attempt when scheduling a retry', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));

    $aggregate->failDeviationAttempt(1, AnalysisFailureCode::WorkerFailed, $now(), $now()->modify('+10 seconds'));

    expect($aggregate->deviations()->status())->toBe(AnalysisStatus::Pending);
    expect($aggregate->deviations()->attempts()[0]->failureCode)->toBe(AnalysisFailureCode::WorkerFailed);
    expect($aggregate->deviations()->currentAttempt()->number)->toBe(2);
    expect($aggregate->deviations()->currentAttempt()->cycleAttempt)->toBe(2);
    expect($aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes')))->toBeFalse();
    expect($aggregate->startDeviationAttempt(2, $now(), $now()->modify('+2 minutes')))->toBeFalse();
    expect($aggregate->startDeviationAttempt(2, $now()->modify('+10 seconds'), $now()->modify('+2 minutes')))->toBeTrue();
    expect($aggregate->failDeviationAttempt(1, AnalysisFailureCode::WorkerFailed, $now(), null))->toBeFalse();
});

it('retries a failed stage explicitly while retaining history and renewing the retry budget', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));
    $aggregate->failDeviationAttempt(1, AnalysisFailureCode::CalculationFailed, $now(), null);

    expect($aggregate->retryDeviations($now()))->toBeTrue();
    expect($aggregate->retryDeviations($now()))->toBeFalse();
    expect($aggregate->deviations()->currentAttempt()->cycleAttempt)->toBe(1);
    expect($aggregate->deviations()->currentAttempt()->number)->toBe(2);
    expect($aggregate->deviations()->attempts())->toHaveCount(2);
});

it('does not interrupt a live calculation on explicit retry', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));

    expect(fn () => $aggregate->retryDeviations($now()))->toThrow(InvalidAnalysisTransition::class);
});

it('recovers only expired processing and rejects a late result', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $result = (new WorkoutDeviationCalculator)->calculate($aggregate->deviations()->snapshot);
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));

    expect($aggregate->recoverExpiredDeviationAttempt($now(), $now()))->toBeFalse();
    expect($aggregate->completeDeviationAttempt(1, $result, $now()->modify('+2 minutes')))->toBeFalse();
    expect($aggregate->recoverExpiredDeviationAttempt($now()->modify('+2 minutes'), $now()->modify('+3 minutes')))->toBeTrue();
    expect($aggregate->deviations()->attempts()[0]->failureCode)->toBe(AnalysisFailureCode::AttemptTimedOut);
    expect($aggregate->completeDeviationAttempt(1, $result, $now()->modify('+3 minutes')))->toBeFalse();
});

it('rejects a result from another snapshot', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));
    $other = (new WorkoutDeviationCalculator)->calculate(Fixture::workout(Fixture::exercise(actual: [[8, 50_000]])));

    expect(fn () => $aggregate->completeDeviationAttempt(1, $other, $now()))->toThrow(InvalidArgumentException::class);
    expect($aggregate->deviations()->status())->toBe(AnalysisStatus::Processing);
});

it('does not expose mutable stage state outside the aggregate', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $stage = $aggregate->deviations();
    $stage->start(1, $now(), $now()->modify('+2 minutes'));

    expect($aggregate->deviations()->status())->toBe(AnalysisStatus::Pending);
});

it('restores the full history and result of a completed stage', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $result = (new WorkoutDeviationCalculator)->calculate($aggregate->deviations()->snapshot);
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));
    $aggregate->completeDeviationAttempt(1, $result, $now());
    $stage = $aggregate->deviations();

    $restored = WorkoutDeviationAnalysis::restore($stage->snapshot, $stage->attempts(), $result);

    expect($restored)->toEqual($stage);
    expect(fn () => WorkoutDeviationAnalysis::restore($stage->snapshot, $stage->attempts(), null))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects scheduling an analysis before the workout completed', function () {
    expect(fn () => WorkoutAnalysis::initialize(
        Fixture::workout(Fixture::exercise()),
        new DateTimeImmutable('2026-09-14 12:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects incomplete or noncontiguous restored attempt histories', function (int $case) use ($analysis, $now) {
    $stage = $analysis()->deviations();
    $first = $stage->currentAttempt();
    $second = new AnalysisAttempt(2, 2, AnalysisStatus::Pending, $now());
    $attempts = match ($case) {
        1 => [],
        2 => [1 => $first],
        3 => [$second],
        default => [$first, $second],
    };

    expect(fn () => WorkoutDeviationAnalysis::restore($stage->snapshot, $attempts, null))
        ->toThrow(InvalidArgumentException::class);
})->with([1, 2, 3, 4]);

it('does not mutate the running attempt when retry scheduling is invalid', function () use ($analysis, $now) {
    $aggregate = $analysis();
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));

    expect(fn () => $aggregate->failDeviationAttempt(1, AnalysisFailureCode::WorkerFailed, $now(), $now()->modify('-1 second')))
        ->toThrow(InvalidArgumentException::class);
    expect($aggregate->deviations()->status())->toBe(AnalysisStatus::Processing);
    expect($aggregate->deviations()->attempts())->toHaveCount(1);
});

$completedAnalysis = static function () use ($analysis, $now): WorkoutAnalysis {
    $aggregate = $analysis();
    $result = (new WorkoutDeviationCalculator)->calculate($aggregate->deviations()->snapshot);
    $aggregate->startDeviationAttempt(1, $now(), $now()->modify('+2 minutes'));
    $aggregate->completeDeviationAttempt(1, $result, $now()->modify('+1 second'));

    return $aggregate;
};
$context = static fn (): AnalysisContextSnapshot => new AnalysisContextSnapshot(
    Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now()->modify('+1 minute'),
);

it('attaches context once and reuses it for an equivalent repeated request', function () use ($completedAnalysis, $context) {
    $aggregate = $completedAnalysis();
    $snapshot = $context();

    expect($aggregate->attachContext($snapshot))->toBeTrue();
    expect($aggregate->attachContext($context()))->toBeFalse();
    expect($aggregate->context())->toBe($snapshot);
});

it('rejects context before deviations are completed without changing the stage', function () use ($analysis, $context) {
    $aggregate = $analysis();

    expect(fn () => $aggregate->attachContext($context()))->toThrow(InvalidAnalysisContext::class);
    expect($aggregate->context())->toBeNull();
    expect($aggregate->deviations()->status())->toBe(AnalysisStatus::Pending);
});

it('rejects context calculated from different current workout data', function () use ($completedAnalysis, $now) {
    $aggregate = $completedAnalysis();
    $other = new AnalysisContextSnapshot(Fixture::result(exercise: Fixture::exercise(actual: [[8, 50_000]])), new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now()->modify('+1 minute'));

    expect(fn () => $aggregate->attachContext($other))->toThrow(InvalidAnalysisContext::class);
    expect($aggregate->context())->toBeNull();
});

it('rejects a capture timestamp before deviations were completed', function () use ($completedAnalysis, $now) {
    $aggregate = $completedAnalysis();
    $early = new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now());

    expect(fn () => $aggregate->attachContext($early))->toThrow(InvalidAnalysisContext::class);
    expect($aggregate->context())->toBeNull();
});

it('refuses to replace an attached context with a later capture', function () use ($completedAnalysis, $context, $now) {
    $aggregate = $completedAnalysis();
    $original = $context();
    $aggregate->attachContext($original);
    $later = new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now()->modify('+2 minutes'));

    expect(fn () => $aggregate->attachContext($later))->toThrow(InvalidAnalysisContext::class);
    expect($aggregate->context())->toBe($original);
});

it('restores a context through the same invariants and keeps it when cloning', function () use ($completedAnalysis, $context) {
    $original = $completedAnalysis();
    $snapshot = $context();

    $restored = WorkoutAnalysis::restore(new WorkoutAnalysisId(9), $original->deviations(), $snapshot);
    $cloned = clone $restored;

    expect($restored->context())->toBe($snapshot);
    expect($cloned->attachContext($context()))->toBeFalse();
    expect($cloned->context())->toBe($snapshot);
});

it('rejects restoring a context on an unfinished analysis', function () use ($analysis, $context) {
    expect(fn () => WorkoutAnalysis::restore(new WorkoutAnalysisId(9), $analysis()->deviations(), $context()))
        ->toThrow(InvalidAnalysisContext::class);
});
