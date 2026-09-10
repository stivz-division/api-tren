<?php

use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

it('preserves weight as an integer number of grams', function () {
    $weight = new WorkingWeight(100_000);

    expect($weight->grams)->toBe(100_000);
});

it('allows zero weight for bodyweight exercises', function () {
    expect((new WorkingWeight(0))->grams)->toBe(0);
});

it('rejects a negative weight', function () {
    expect(fn () => new WorkingWeight(-1))->toThrow(
        InvalidArgumentException::class,
        'Рабочий вес не может быть отрицательным.',
    );
});
