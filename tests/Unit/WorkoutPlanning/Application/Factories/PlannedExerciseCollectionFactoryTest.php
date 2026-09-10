<?php

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\Exceptions\ExerciseNotFound;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Domain\Exceptions\ExerciseAlreadyPlanned;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use Tests\Support\WorkoutPlanning\InMemoryExerciseCatalog;

it('builds planned exercises in input order', function () {
    $factory = new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10, 20));

    $collection = $factory->create([
        new PlannedExerciseInput(20, 4, 8, 50_000),
        new PlannedExerciseInput(10, 3, 6, 100_000),
    ]);

    expect(array_map(
        static fn ($exercise): array => [
            $exercise->exerciseId->value,
            $exercise->setsCount->value,
            $exercise->repetitionsPerSet->value,
            $exercise->workingWeight->grams,
            $exercise->position->value,
        ],
        $collection->all(),
    ))->toBe([
        [20, 4, 8, 50_000, 1],
        [10, 3, 6, 100_000, 2],
    ]);
});

it('rejects an empty exercise list', function () {
    $factory = new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog);

    expect(fn () => $factory->create([]))
        ->toThrow(TrainingProgramMustContainExercise::class);
});

it('rejects exercises missing from the catalog', function () {
    $factory = new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10));

    expect(fn () => $factory->create([
        new PlannedExerciseInput(10, 3, 6, 100_000),
        new PlannedExerciseInput(20, 4, 8, 50_000),
        new PlannedExerciseInput(30, 2, 10, 30_000),
    ]))->toThrow(
        ExerciseNotFound::class,
        'Упражнения не найдены: 20, 30.',
    );
});

it('rejects a repeated exercise', function () {
    $factory = new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10));

    expect(fn () => $factory->create([
        new PlannedExerciseInput(10, 3, 6, 100_000),
        new PlannedExerciseInput(10, 4, 8, 90_000),
    ]))->toThrow(ExerciseAlreadyPlanned::class);
});
