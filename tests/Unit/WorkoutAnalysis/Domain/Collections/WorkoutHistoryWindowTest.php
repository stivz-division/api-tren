<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('orders history chronologically and breaks equal timestamps by ascending session id', function () {
    $older = new WorkoutHistoryEntry(Fixture::result(sessionId: 90, completedAt: '2026-09-13 12:00:00+00:00'));
    $first = new WorkoutHistoryEntry(Fixture::result(sessionId: 48, completedAt: '2026-09-14 12:00:00+00:00'));
    $second = new WorkoutHistoryEntry(Fixture::result(sessionId: 49, completedAt: '2026-09-14 15:00:00+03:00'));

    $window = new WorkoutHistoryWindow(3, $second, $older, $first);

    expect($window->all())->toBe([$older, $first, $second]);
    expect(iterator_to_array($window))->toBe([$older, $first, $second]);
});

it('does not silently discard duplicate or excess entries', function (int $limit, int $secondId) {
    $first = new WorkoutHistoryEntry(Fixture::result(sessionId: 1));
    $second = new WorkoutHistoryEntry(Fixture::result(sessionId: $secondId));

    expect(fn () => new WorkoutHistoryWindow($limit, $first, $second))->toThrow(InvalidAnalysisContext::class);
})->with(['duplicate session' => [20, 1], 'exceeds independent limit' => [1, 2]]);

it('uses twenty by default and permits a larger configured history window', function () {
    $entries = array_map(static fn (int $id): WorkoutHistoryEntry => new WorkoutHistoryEntry(Fixture::result(sessionId: $id)), range(1, 28));

    expect((new WorkoutHistoryWindow)->limit)->toBe(20);
    expect(new WorkoutHistoryWindow(28, ...$entries))->toHaveCount(28);
});

it('rejects a nonpositive window limit', function (int $limit) {
    expect(fn () => new WorkoutHistoryWindow($limit))->toThrow(InvalidAnalysisContext::class);
})->with([0, -1]);
