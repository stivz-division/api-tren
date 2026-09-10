<?php

use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;

it('trims and preserves a custom name', function () {
    $name = new ProgramName('  Грудь и трицепс  ');

    expect($name->value)->toBe('Грудь и трицепс');
});

it('provides the default program name', function () {
    expect(ProgramName::default()->value)->toBe('Тренировка');
});

it('rejects a blank name', function (string $value) {
    expect(fn () => new ProgramName($value))->toThrow(
        InvalidArgumentException::class,
        'Название программы тренировок не может быть пустым.',
    );
})->with([
    'empty string' => '',
    'ASCII whitespace' => '   ',
    'non-breaking spaces' => "\u{00A0}\u{00A0}",
    'em spaces' => "\u{2003}\u{2003}",
]);

it('compares names by value', function () {
    $name = new ProgramName('Силовая');

    expect($name->equals(new ProgramName('Силовая')))->toBeTrue();
    expect($name->equals(new ProgramName('Кардио')))->toBeFalse();
});
