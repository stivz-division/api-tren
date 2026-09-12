<?php

use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\Exceptions\InvalidPlannedSetOrder;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;
use App\WorkoutPlanning\Domain\ValueObjects\Repetitions;
use App\WorkoutPlanning\Domain\ValueObjects\SetPosition;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

$plannedSet = static fn (int $position, int $repetitions = 6, int $weightInGrams = 100_000): PlannedSet => new PlannedSet(
    new SetPosition($position),
    new Repetitions($repetitions),
    new WorkingWeight($weightInGrams),
);

it('keeps individual sets ordered by contiguous positions', function () use ($plannedSet): void {
    $sets = new PlannedSetCollection(
        $plannedSet(3, 1, 130_000),
        $plannedSet(1, 3, 80_000),
        $plannedSet(2, 6, 100_000),
    );

    expect(array_map(
        static fn (PlannedSet $set): array => [
            $set->position->value,
            $set->repetitions->value,
            $set->workingWeight->grams,
        ],
        $sets->all(),
    ))->toBe([
        [1, 3, 80_000],
        [2, 6, 100_000],
        [3, 1, 130_000],
    ]);
});

it('rejects duplicate or non-contiguous positions', function (array $positions) use ($plannedSet): void {
    expect(fn () => new PlannedSetCollection(...array_map(
        static function (mixed $position) use ($plannedSet): PlannedSet {
            if (! is_int($position)) {
                throw new LogicException('Позиция тестового подхода должна быть целым числом.');
            }

            return $plannedSet($position);
        },
        $positions,
    )))
        ->toThrow(InvalidPlannedSetOrder::class);
})->with([
    'duplicate' => [[1, 1]],
    'gap' => [[1, 3]],
    'does not start at one' => [[2]],
]);
