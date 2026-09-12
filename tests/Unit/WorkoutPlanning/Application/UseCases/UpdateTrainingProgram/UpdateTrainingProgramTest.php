<?php

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\DTO\PlannedSetInput;
use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Application\Factories\PlannedSetCollectionFactory;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgramInput;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\Repetitions;
use App\WorkoutPlanning\Domain\ValueObjects\SetPosition;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use Tests\Support\WorkoutPlanning\InMemoryExerciseCatalog;
use Tests\Support\WorkoutPlanning\InMemoryTrainingProgramRepository;
use Tests\Support\WorkoutPlanning\SynchronousTrainingProgramMutationLock;

$input = static fn (int $exerciseId, int $count, int $repetitions, int $weightInGrams): PlannedExerciseInput => new PlannedExerciseInput(
    $exerciseId,
    array_fill(0, $count, new PlannedSetInput($repetitions, $weightInGrams)),
);

$sets = static fn (int $count, int $repetitions, int $weightInGrams): PlannedSetCollection => new PlannedSetCollection(...array_map(
    static fn (int $position): PlannedSet => new PlannedSet(new SetPosition($position), new Repetitions($repetitions), new WorkingWeight($weightInGrams)),
    range(1, $count),
));

$trainingProgramForUpdate = static fn (): TrainingProgram => TrainingProgram::restore(
    new TrainingProgramId(5),
    new UserId(7),
    Weekday::Monday,
    new PlannedExerciseCollection(new PlannedExercise(
        new ExerciseId(10),
        $sets(3, 6, 100_000),
        new ExercisePosition(1),
    )),
    ProgramName::default(),
);

it('replaces the editable program state in one operation', function () use ($input, $trainingProgramForUpdate) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForUpdate());
    $lock = new SynchronousTrainingProgramMutationLock;
    $useCase = new UpdateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10, 20), new PlannedSetCollectionFactory),
        $lock,
    );

    $result = $useCase->handle(new UpdateTrainingProgramInput(
        userId: 7,
        trainingProgramId: 5,
        name: 'Силовая тренировка',
        exercises: [
            $input(20, 4, 8, 50_000),
            $input(10, 5, 5, 110_000),
        ],
    ));

    expect([$result->name, $result->weekday])->toBe(['Силовая тренировка', 1]);
    expect(array_map(
        static fn ($exercise): array => [
            $exercise->exerciseId,
            array_map(static fn ($set): array => [$set->position, $set->repetitions, $set->workingWeightInGrams], $exercise->sets),
            $exercise->position,
        ],
        $result->exercises,
    ))->toBe([
        [20, [[1, 8, 50_000], [2, 8, 50_000], [3, 8, 50_000], [4, 8, 50_000]], 1],
        [10, [[1, 5, 110_000], [2, 5, 110_000], [3, 5, 110_000], [4, 5, 110_000], [5, 5, 110_000]], 2],
    ]);
    expect($repository->saveCalls)->toBe(1);
    $savedProgram = $repository->find(new TrainingProgramId(5));
    expect($savedProgram?->name->value)->toBe('Силовая тренировка');
    expect(array_map(
        static fn (PlannedExercise $exercise): int => $exercise->exerciseId->value,
        $savedProgram?->plannedExercises() ?? [],
    ))->toBe([20, 10]);
    expect($lock->userIds)->toBe([7]);
});

it('does not expose another users program', function () use ($input, $trainingProgramForUpdate) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForUpdate());
    $useCase = new UpdateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10), new PlannedSetCollectionFactory),
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new UpdateTrainingProgramInput(
        userId: 8,
        trainingProgramId: 5,
        name: 'Чужая программа',
        exercises: [$input(10, 3, 6, 100_000)],
    )))->toThrow(TrainingProgramNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});

it('does not change the persisted program when replacement is invalid', function () use ($trainingProgramForUpdate) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForUpdate());
    $useCase = new UpdateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10), new PlannedSetCollectionFactory),
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new UpdateTrainingProgramInput(
        userId: 7,
        trainingProgramId: 5,
        name: 'Не должна сохраниться',
        exercises: [],
    )))->toThrow(TrainingProgramMustContainExercise::class);

    $savedProgram = $repository->find(new TrainingProgramId(5));
    expect($savedProgram?->name->value)->toBe('Тренировка');
    expect($repository->saveCalls)->toBe(0);
});
