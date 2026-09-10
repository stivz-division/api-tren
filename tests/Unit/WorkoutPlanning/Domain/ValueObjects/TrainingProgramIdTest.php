<?php

use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;

it('preserves a positive identifier', function () {
    $id = new TrainingProgramId(42);

    expect($id->value)->toBe(42);
});

it('rejects a non-positive identifier', function (int $value) {
    expect(fn () => new TrainingProgramId($value))->toThrow(
        InvalidArgumentException::class,
        'Идентификатор программы тренировок должен быть положительным целым числом.',
    );
})->with([
    'zero' => 0,
    'negative value' => -1,
]);

it('compares identifiers by value', function () {
    $id = new TrainingProgramId(42);

    expect($id->equals(new TrainingProgramId(42)))->toBeTrue();
    expect($id->equals(new TrainingProgramId(43)))->toBeFalse();
});
