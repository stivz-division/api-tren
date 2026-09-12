<?php

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\DTO\PlannedSetInput;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Application\Factories\PlannedSetCollectionFactory;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgramInput;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\InvalidWeekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
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

it('creates and saves a program with an auto-increment identity', function () use ($input) {
    $repository = new InMemoryTrainingProgramRepository(nextId: 41);
    $lock = new SynchronousTrainingProgramMutationLock;
    $useCase = new CreateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10, 20), new PlannedSetCollectionFactory),
        $lock,
    );

    $result = $useCase->handle(new CreateTrainingProgramInput(
        userId: 7,
        weekday: 1,
        name: null,
        exercises: [
            $input(10, 3, 6, 100_000),
            $input(20, 4, 8, 50_000),
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
            array_map(static fn ($set): array => [$set->position, $set->repetitions, $set->workingWeightInGrams], $exercise->sets),
            $exercise->position,
        ],
        $result->exercises,
    ))->toBe([
        [10, [[1, 6, 100_000], [2, 6, 100_000], [3, 6, 100_000]], 1],
        [20, [[1, 8, 50_000], [2, 8, 50_000], [3, 8, 50_000], [4, 8, 50_000]], 2],
    ]);
    expect($repository->addCalls)->toBe(1);
    expect($repository->saveCalls)->toBe(0);
    expect($repository->find(new TrainingProgramId(41)))->not->toBeNull();
    expect($lock->userIds)->toBe([7]);
});

it('rejects a second program for the same user and weekday', function () use ($input, $sets) {
    $existingProgram = TrainingProgram::restore(
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
    $repository = new InMemoryTrainingProgramRepository(41, $existingProgram);
    $useCase = new CreateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10), new PlannedSetCollectionFactory),
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new CreateTrainingProgramInput(
        userId: 7,
        weekday: 1,
        name: 'Грудь',
        exercises: [$input(10, 3, 6, 100_000)],
    )))->toThrow(TrainingProgramAlreadyExists::class);
    expect($repository->addCalls)->toBe(0);
    expect($repository->saveCalls)->toBe(0);
});

it('rejects an unsupported weekday with a Russian domain error', function () use ($input) {
    $repository = new InMemoryTrainingProgramRepository(nextId: 41);
    $useCase = new CreateTrainingProgram(
        $repository,
        new PlannedExerciseCollectionFactory(new InMemoryExerciseCatalog(10), new PlannedSetCollectionFactory),
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new CreateTrainingProgramInput(
        userId: 7,
        weekday: 0,
        name: null,
        exercises: [$input(10, 3, 6, 100_000)],
    )))->toThrow(
        InvalidWeekday::class,
        'День недели должен быть числом от 1 до 7.',
    );
});
