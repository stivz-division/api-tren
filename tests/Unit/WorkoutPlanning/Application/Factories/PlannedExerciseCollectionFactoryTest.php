<?php

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\DTO\PlannedSetInput;
use App\WorkoutPlanning\Application\Exceptions\ExerciseNotFound;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Application\Factories\PlannedSetCollectionFactory;
use App\WorkoutPlanning\Domain\Exceptions\ExerciseAlreadyPlanned;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use Tests\Support\WorkoutPlanning\InMemoryExerciseCatalog;

$input = static fn (int $exerciseId, int $count, int $repetitions, int $weightInGrams): PlannedExerciseInput => new PlannedExerciseInput(
    $exerciseId,
    array_fill(0, $count, new PlannedSetInput($repetitions, $weightInGrams)),
);

$factory = static fn (int ...$exerciseIds): PlannedExerciseCollectionFactory => new PlannedExerciseCollectionFactory(
    new InMemoryExerciseCatalog(...$exerciseIds),
    new PlannedSetCollectionFactory,
);

it('builds planned exercises and their sets in input order', function () use ($factory, $input) {
    $factory = $factory(10, 20);

    $collection = $factory->create([
        $input(20, 4, 8, 50_000),
        $input(10, 3, 6, 100_000),
    ]);

    expect(array_map(
        static fn ($exercise): array => [
            $exercise->exerciseId->value,
            array_map(static fn ($set): array => [
                $set->position->value,
                $set->repetitions->value,
                $set->workingWeight->grams,
            ], $exercise->plannedSets()),
            $exercise->position->value,
        ],
        $collection->all(),
    ))->toBe([
        [20, [[1, 8, 50_000], [2, 8, 50_000], [3, 8, 50_000], [4, 8, 50_000]], 1],
        [10, [[1, 6, 100_000], [2, 6, 100_000], [3, 6, 100_000]], 2],
    ]);
});

it('rejects an empty exercise list', function () use ($factory) {
    $factory = $factory();

    expect(fn () => $factory->create([]))
        ->toThrow(TrainingProgramMustContainExercise::class);
});

it('rejects exercises missing from the catalog', function () use ($factory, $input) {
    $factory = $factory(10);

    expect(fn () => $factory->create([
        $input(10, 3, 6, 100_000),
        $input(20, 4, 8, 50_000),
        $input(30, 2, 10, 30_000),
    ]))->toThrow(
        ExerciseNotFound::class,
        'Упражнения не найдены: 20, 30.',
    );
});

it('rejects a repeated exercise', function () use ($factory, $input) {
    $factory = $factory(10);

    expect(fn () => $factory->create([
        $input(10, 3, 6, 100_000),
        $input(10, 4, 8, 90_000),
    ]))->toThrow(ExerciseAlreadyPlanned::class);
});
