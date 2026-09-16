<?php

use App\WorkoutAnalysis\Domain\ValueObjects\LoadTotals;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('returns zero totals for an empty actual list', function () {
    $totals = LoadTotals::fromSets(Fixture::sets([]));

    expect($totals->sets)->toBe(0);
    expect($totals->repetitions)->toBe(0);
    expect($totals->volume)->toBe(0);
});

it('adds loads without changing either input', function () {
    $first = LoadTotals::fromSets(Fixture::sets([[10, 50_000]]));
    $second = LoadTotals::fromSets(Fixture::sets([[8, 60_000], [6, 70_000]]));

    $combined = $first->plus($second);

    expect($combined->sets)->toBe(3);
    expect($combined->repetitions)->toBe(24);
    expect($combined->volume)->toBe(1_400_000);
    expect($first->sets)->toBe(1);
    expect($second->sets)->toBe(2);
});

it('rejects repetition overflow even with zero external weight', function () {
    expect(fn () => LoadTotals::fromSets(Fixture::sets([[PHP_INT_MAX, 0], [1, 0]])))
        ->toThrow(OverflowException::class);
});

it('rejects a volume total that exceeds the integer range', function () {
    expect(fn () => LoadTotals::fromSets(Fixture::sets([[1, PHP_INT_MAX], [1, 1]])))
        ->toThrow(OverflowException::class);
});

it('accepts totals exactly at the integer limit', function () {
    $totals = LoadTotals::fromSets(Fixture::sets([[1, PHP_INT_MAX - 1], [1, 1]]));

    expect($totals->volume)->toBe(PHP_INT_MAX);
});
