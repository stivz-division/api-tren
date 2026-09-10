<?php

use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;

it('preserves a positive position', function () {
    $position = new ExercisePosition(2);

    expect($position->value)->toBe(2);
});

it('rejects a non-positive position', function (int $value) {
    expect(fn () => new ExercisePosition($value))->toThrow(
        InvalidArgumentException::class,
        'Позиция упражнения должна быть положительным целым числом.',
    );
})->with([
    'zero' => 0,
    'negative value' => -1,
]);
