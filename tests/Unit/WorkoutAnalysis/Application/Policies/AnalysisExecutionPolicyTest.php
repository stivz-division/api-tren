<?php

use App\WorkoutAnalysis\Application\Policies\AnalysisExecutionPolicy;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;

it('uses configured timeouts and repeats the last backoff within the retry budget', function () {
    $policy = new AnalysisExecutionPolicy(5, 90, 20, [2, 7]);
    $now = new DateTimeImmutable('2026-09-17 12:00:00+00:00');
    $attempt = new AnalysisAttempt(4, 4, AnalysisStatus::Pending, $now);

    expect($policy->expiresAt($now))->toEqual($now->modify('+90 seconds'));
    expect($policy->retryAt($attempt, AnalysisFailureCode::WorkerFailed, $now))->toEqual($now->modify('+7 seconds'));
    expect($policy->pendingNeedsDispatch($attempt, $now->modify('+19 seconds')))->toBeFalse();
    expect($policy->pendingNeedsDispatch($attempt, $now->modify('+20 seconds')))->toBeTrue();
});

it('rejects nonpositive limits', function (int $max, int $timeout, int $grace) {
    expect(fn () => new AnalysisExecutionPolicy($max, $timeout, $grace))->toThrow(InvalidArgumentException::class);
})->with(['attempts' => [0, 120, 60], 'timeout' => [3, 0, 60], 'pending grace' => [3, 120, 0]]);

it('rejects invalid retry delay lists', function (int $case) {
    $delays = match ($case) {
        1 => [],
        2 => [-1],
        default => [2 => 5],
    };

    expect(fn () => new AnalysisExecutionPolicy(retryDelaysInSeconds: $delays))->toThrow(InvalidArgumentException::class);
})->with([1, 2, 3]);

it('does not retry a deterministic calculation failure', function (AnalysisFailureCode $code) {
    $now = new DateTimeImmutable('2026-09-17');
    $attempt = new AnalysisAttempt(1, 1, AnalysisStatus::Pending, $now);

    expect((new AnalysisExecutionPolicy)->retryAt($attempt, $code, $now))->toBeNull();
})->with([AnalysisFailureCode::ArithmeticOverflow, AnalysisFailureCode::CalculationFailed]);
