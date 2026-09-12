<?php

use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;

it('normalizes an exercise snapshot name', function () {
    expect((new ExerciseName('  Жим лежа  '))->value)->toBe('Жим лежа');
});

it('rejects an empty exercise snapshot name', function (string $value) {
    expect(fn () => new ExerciseName($value))->toThrow(InvalidArgumentException::class);
})->with(['', '   ']);
