<?php

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;

it('rejects impossible attempt numbers', function (int $number, int $cycle) {
    expect(fn () => new AnalysisAttempt($number, $cycle, AnalysisStatus::Pending, new DateTimeImmutable))
        ->toThrow(InvalidArgumentException::class);
})->with([[0, 1], [1, 0], [1, 2]]);

it('rejects restoring a running or terminal attempt without its timestamps', function (AnalysisStatus $status) {
    expect(fn () => new AnalysisAttempt(1, 1, $status, new DateTimeImmutable('2026-09-17')))
        ->toThrow(InvalidArgumentException::class);
})->with([AnalysisStatus::Processing, AnalysisStatus::Completed, AnalysisStatus::Failed]);

it('rejects invalid temporal ordering', function (string $start, string $expires, string $finish) {
    $scheduled = new DateTimeImmutable('2026-09-17 12:00:00+00:00');

    expect(fn () => new AnalysisAttempt(
        1, 1, AnalysisStatus::Completed, $scheduled,
        new DateTimeImmutable($start), new DateTimeImmutable($expires), new DateTimeImmutable($finish),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'before scheduled' => ['2026-09-17 11:00:00+00:00', '2026-09-17 13:00:00+00:00', '2026-09-17 12:01:00+00:00'],
    'expiry before start' => ['2026-09-17 12:00:00+00:00', '2026-09-17 11:00:00+00:00', '2026-09-17 12:01:00+00:00'],
    'finish before start' => ['2026-09-17 12:00:00+00:00', '2026-09-17 13:00:00+00:00', '2026-09-17 11:00:00+00:00'],
    'success after expiry' => ['2026-09-17 12:00:00+00:00', '2026-09-17 13:00:00+00:00', '2026-09-17 14:00:00+00:00'],
]);

it('requires an error for a failed attempt', function () {
    $now = new DateTimeImmutable('2026-09-17');

    expect(fn () => new AnalysisAttempt(1, 1, AnalysisStatus::Failed, $now, $now, $now->modify('+1 minute'), $now))
        ->toThrow(InvalidArgumentException::class);
});

it('does not allow beginning or finishing an attempt from the wrong state', function () {
    $now = new DateTimeImmutable('2026-09-17');
    $pending = new AnalysisAttempt(1, 1, AnalysisStatus::Pending, $now);
    $finished = $pending->start($now, $now->modify('+1 minute'))->finish($now, AnalysisFailureCode::WorkerFailed);

    expect(fn () => $pending->finish($now))->toThrow(InvalidArgumentException::class);
    expect(fn () => $finished->start($now, $now->modify('+1 minute')))->toThrow(InvalidArgumentException::class);
});
