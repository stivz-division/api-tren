<?php

use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;

it('preserves a positive identifier', function () {
    $id = new ExerciseId(7);

    expect($id->value)->toBe(7);
});

it('rejects a non-positive identifier', function (int $value) {
    expect(fn () => new ExerciseId($value))->toThrow(
        InvalidArgumentException::class,
        'Идентификатор упражнения должен быть положительным целым числом.',
    );
})->with([
    'zero' => 0,
    'negative value' => -1,
]);

it('compares identifiers by value', function () {
    $id = new ExerciseId(7);

    expect($id->equals(new ExerciseId(7)))->toBeTrue();
    expect($id->equals(new ExerciseId(8)))->toBeFalse();
});
