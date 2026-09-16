<?php

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('sorts sets by position and preserves their values', function () {
    $first = Fixture::set(1, 10, 50_000);
    $second = Fixture::set(2, 6, 70_000);

    $sets = new SetSnapshotCollection($second, $first);

    expect($sets->all())->toBe([$first, $second]);
    expect(iterator_to_array($sets))->toBe([$first, $second]);
    expect($sets)->toHaveCount(2);
});

it('allows an empty list for skipped exercises', function () {
    expect((new SetSnapshotCollection)->all())->toBe([]);
});

it('rejects ambiguous or incomplete set ordering', function (int $first, int $second) {
    expect(fn () => new SetSnapshotCollection(Fixture::set($first), Fixture::set($second)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'duplicates' => [1, 1],
    'gap' => [1, 3],
    'missing first' => [2, 3],
]);
