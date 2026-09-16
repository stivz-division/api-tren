<?php

use App\WorkoutAnalysis\Domain\ValueObjects\SetPosition;

it('accepts a positive set position', function () {
    expect((new SetPosition(1))->value)->toBe(1);
});

it('rejects a non-positive set position', function (int $value) {
    expect(fn () => new SetPosition($value))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);
