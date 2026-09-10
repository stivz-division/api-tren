<?php

use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;

it('preserves a positive sets count', function () {
    $sets = new SetsCount(3);

    expect($sets->value)->toBe(3);
});

it('rejects a non-positive sets count', function (int $value) {
    expect(fn () => new SetsCount($value))->toThrow(
        InvalidArgumentException::class,
        'Количество подходов должно быть не меньше одного.',
    );
})->with([
    'zero' => 0,
    'negative value' => -1,
]);
