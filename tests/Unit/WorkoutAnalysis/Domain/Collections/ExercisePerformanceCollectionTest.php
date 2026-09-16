<?php

use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('orders exercises by their historical positions', function () {
    $first = Fixture::exercise(id: 20, position: 1);
    $second = Fixture::exercise(id: 10, position: 2);

    $exercises = new ExercisePerformanceCollection($second, $first);

    expect($exercises->all())->toBe([$first, $second]);
    expect(iterator_to_array($exercises))->toBe([$first, $second]);
    expect($exercises)->toHaveCount(2);
});

it('rejects duplicate exercises even when their positions differ', function () {
    expect(fn () => new ExercisePerformanceCollection(
        Fixture::exercise(id: 10, position: 1),
        Fixture::exercise(id: 10, position: 2),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid exercise positions', function (int $first, int $second) {
    expect(fn () => new ExercisePerformanceCollection(
        Fixture::exercise(id: 10, position: $first),
        Fixture::exercise(id: 20, position: $second),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'duplicate position' => [1, 1],
    'gap' => [1, 3],
    'missing first' => [2, 3],
]);
