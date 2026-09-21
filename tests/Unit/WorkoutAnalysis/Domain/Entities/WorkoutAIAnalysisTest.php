<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAIAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('ignores duplicate deliveries and responses from superseded attempts', function (): void {
    $now = new DateTimeImmutable('2026-09-21T12:00:00Z');
    $context = new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now);
    $result = new WorkoutAIResult(new WorkoutAnalysisId(1), $context, 'План выполнен.', 'Истории нет.', 'model', 'resp_1', 1, 1);
    $stage = WorkoutAIAnalysis::pending($now);
    expect($stage->start(1, $now, $now->modify('+60 seconds')))->toBeTrue();
    expect($stage->start(1, $now, $now->modify('+60 seconds')))->toBeFalse();
    expect($stage->fail(1, AnalysisFailureCode::ProviderUnavailable, $now->modify('+1 second'), $now->modify('+6 seconds')))->toBeTrue();
    expect($stage->complete(1, $result, $now->modify('+2 seconds')))->toBeFalse();
    expect($stage->start(2, $now->modify('+6 seconds'), $now->modify('+66 seconds')))->toBeTrue();
    expect($stage->complete(2, $result, $now->modify('+7 seconds')))->toBeTrue();
    expect($stage->retry($now->modify('+8 seconds')))->toBeFalse();
    expect($stage->status())->toBe(AnalysisStatus::Completed);
    expect(WorkoutAIAnalysis::restore($stage->attempts(), $result))->toEqual($stage);
});

it('rejects a result for a noncompleted stage during restoration', function (): void {
    $now = new DateTimeImmutable('2026-09-21T12:00:00Z');
    $stage = WorkoutAIAnalysis::pending($now);
    $context = new AnalysisContextSnapshot(Fixture::result(), new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now);
    $result = new WorkoutAIResult(new WorkoutAnalysisId(1), $context, 'План выполнен.', 'Истории нет.', 'model', 'resp_1', 1, 1);

    expect(fn () => WorkoutAIAnalysis::restore($stage->attempts(), $result))->toThrow(InvalidArgumentException::class);
});
