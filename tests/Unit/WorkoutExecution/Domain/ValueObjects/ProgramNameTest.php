<?php

use App\WorkoutExecution\Domain\ValueObjects\ProgramName;

it('normalizes a program snapshot name', function () {
    expect((new ProgramName('  Грудь  '))->value)->toBe('Грудь');
});

it('rejects an empty program snapshot name', function (string $value) {
    expect(fn () => new ProgramName($value))->toThrow(InvalidArgumentException::class);
})->with(['', '   ']);
