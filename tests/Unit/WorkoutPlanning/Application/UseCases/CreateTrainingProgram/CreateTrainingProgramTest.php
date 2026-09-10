<?php

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgramInput;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\InvalidWeekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
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

it('creates and saves a program with an auto-increment identity', function () {
    $repository = new InMemoryTrainingProgramRepository(nextId: 41);
    $lock = new SynchronousTrainingProgramMutationLock;
    $useCase = new CreateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10, 20)),
        $lock,
    );

    $result = $useCase->handle(new CreateTrainingProgramInput(
        userId: 7,
        weekday: 1,
        name: null,
        exercises: [
            new PlannedExerciseInput(10, 3, 6, 100_000),
            new PlannedExerciseInput(20, 4, 8, 50_000),
        ],
    ));

    expect([
        $result->id,
        $result->userId,
        $result->weekday,
        $result->name,
    ])->toBe([41, 7, 1, 'Тренировка']);
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
        [10, 3, 6, 100_000, 1],
        [20, 4, 8, 50_000, 2],
    ]);
    expect($repository->addCalls)->toBe(1);
    expect($repository->saveCalls)->toBe(0);
    expect($repository->find(new TrainingProgramId(41)))->not->toBeNull();
    expect($lock->userIds)->toBe([7]);
});

it('rejects a second program for the same user and weekday', function () {
    $existingProgram = TrainingProgram::restore(
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
    $repository = new InMemoryTrainingProgramRepository(41, $existingProgram);
    $useCase = new CreateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10)),
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new CreateTrainingProgramInput(
        userId: 7,
        weekday: 1,
        name: 'Грудь',
        exercises: [new PlannedExerciseInput(10, 3, 6, 100_000)],
    )))->toThrow(TrainingProgramAlreadyExists::class);
    expect($repository->addCalls)->toBe(0);
    expect($repository->saveCalls)->toBe(0);
});

it('rejects an unsupported weekday with a Russian domain error', function () {
    $repository = new InMemoryTrainingProgramRepository(nextId: 41);
    $useCase = new CreateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10)),
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new CreateTrainingProgramInput(
        userId: 7,
        weekday: 0,
        name: null,
        exercises: [new PlannedExerciseInput(10, 3, 6, 100_000)],
    )))->toThrow(
        InvalidWeekday::class,
        'День недели должен быть числом от 1 до 7.',
    );
});
