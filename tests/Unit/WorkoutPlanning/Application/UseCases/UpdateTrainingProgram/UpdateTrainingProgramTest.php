<?php

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgramInput;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use Tests\Support\WorkoutPlanning\InMemoryExerciseCatalog;
use Tests\Support\WorkoutPlanning\InMemoryTrainingProgramRepository;
use Tests\Support\WorkoutPlanning\SynchronousTrainingProgramMutationLock;

$trainingProgramForUpdate = static fn (): TrainingProgram => TrainingProgram::restore(
    new TrainingProgramId(5),
    new UserId(7),
    Weekday::Monday,
    new PlannedExerciseCollection(new PlannedExercise(
        new ExerciseId(10),
        new SetsCount(3),
        new RepetitionsPerSet(6),
        new WorkingWeight(100_000),
        new ExercisePosition(1),
    )),
    ProgramName::default(),
);

it('replaces the editable program state in one operation', function () use ($trainingProgramForUpdate) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForUpdate());
    $lock = new SynchronousTrainingProgramMutationLock;
    $useCase = new UpdateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10, 20)),
        $lock,
    );

    $result = $useCase->handle(new UpdateTrainingProgramInput(
        userId: 7,
        trainingProgramId: 5,
        name: 'Силовая тренировка',
        exercises: [
            new PlannedExerciseInput(20, 4, 8, 50_000),
            new PlannedExerciseInput(10, 5, 5, 110_000),
        ],
    ));

    expect([$result->name, $result->weekday])->toBe(['Силовая тренировка', 1]);
    expect(array_map(
        static fn ($exercise): array => [
            $exercise->exerciseId,
            $exercise->sets,
            $exercise->repetitionsPerSet,
            $exercise->workingWeightInGrams,
            $exercise->position,
        ],
        $result->exercises,
    ))->toBe([
        [20, 4, 8, 50_000, 1],
        [10, 5, 5, 110_000, 2],
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

it('does not expose another users program', function () use ($trainingProgramForUpdate) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForUpdate());
    $useCase = new UpdateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10)),
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new UpdateTrainingProgramInput(
        userId: 8,
        trainingProgramId: 5,
        name: 'Чужая программа',
        exercises: [new PlannedExerciseInput(10, 3, 6, 100_000)],
    )))->toThrow(TrainingProgramNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});

it('does not change the persisted program when replacement is invalid', function () use ($trainingProgramForUpdate) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForUpdate());
    $useCase = new UpdateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10)),
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
