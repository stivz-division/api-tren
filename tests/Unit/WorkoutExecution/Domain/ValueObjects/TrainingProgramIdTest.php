<?php

use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;

it('accepts a positive source program identifier', function () {
    expect((new TrainingProgramId(7))->value)->toBe(7);
});

it('rejects a non-positive source program identifier', function (int $value) {
    expect(fn () => new TrainingProgramId($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
