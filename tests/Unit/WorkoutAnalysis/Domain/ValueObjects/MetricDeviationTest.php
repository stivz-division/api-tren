<?php

use App\WorkoutAnalysis\Domain\ValueObjects\MetricDeviation;

it('calculates signed deviations relative to the plan', function (int $planned, int $actual, int $difference, ?float $percentage) {
    $deviation = new MetricDeviation($planned, $actual);

    expect($deviation->planned)->toBe($planned);
    expect($deviation->actual)->toBe($actual);
    expect($deviation->difference)->toBe($difference);
    expect($deviation->percentage)->toBe($percentage);
})->with([
    'exact' => [10, 10, 0, 0.0],
    'less' => [10, 8, -2, -20.0],
    'more' => [10, 12, 2, 20.0],
    'none performed' => [10, 0, -10, -100.0],
    'zero to zero' => [0, 0, 0, null],
    'zero to positive' => [0, 10, 10, null],
]);

it('keeps fractional percentages without display rounding', function () {
    expect((new MetricDeviation(3, 4))->percentage)->toEqualWithDelta(33.33333333333333, 0.000000001);
});

it('rejects negative source metrics', function (int $planned, int $actual) {
    expect(fn () => new MetricDeviation($planned, $actual))->toThrow(InvalidArgumentException::class);
})->with([
    'planned' => [-1, 0],
    'actual' => [0, -1],
]);
