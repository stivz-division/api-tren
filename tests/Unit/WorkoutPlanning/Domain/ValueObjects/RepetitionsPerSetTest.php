<?php

use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;

it('preserves a positive repetitions count', function () {
    $repetitions = new RepetitionsPerSet(6);

    expect($repetitions->value)->toBe(6);
});

it('rejects a non-positive repetitions count', function (int $value) {
    expect(fn () => new RepetitionsPerSet($value))->toThrow(
        InvalidArgumentException::class,
        'Количество повторений в подходе должно быть не меньше одного.',
    );
})->with([
    'zero' => 0,
    'negative value' => -1,
]);
