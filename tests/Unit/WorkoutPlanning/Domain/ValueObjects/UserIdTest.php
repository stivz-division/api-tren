<?php

use App\WorkoutPlanning\Domain\ValueObjects\UserId;

it('preserves a positive identifier', function () {
    $id = new UserId(111_111_111);

    expect($id->value)->toBe(111_111_111);
});

it('rejects a non-positive identifier', function (int $value) {
    expect(fn () => new UserId($value))->toThrow(
        InvalidArgumentException::class,
        'Идентификатор пользователя должен быть положительным целым числом.',
    );
})->with([
    'zero' => 0,
    'negative value' => -1,
]);

it('compares identifiers by value', function () {
    $id = new UserId(42);

    expect($id->equals(new UserId(42)))->toBeTrue();
    expect($id->equals(new UserId(43)))->toBeFalse();
});
