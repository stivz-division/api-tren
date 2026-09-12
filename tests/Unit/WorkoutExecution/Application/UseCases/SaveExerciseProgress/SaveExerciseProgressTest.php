<?php

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\Factories\WorkoutSetCollectionFactory;
use App\WorkoutExecution\Application\UseCases\SaveExerciseProgress\SaveExerciseProgress;
use App\WorkoutExecution\Application\UseCases\SaveExerciseProgress\SaveExerciseProgressInput;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('replaces the exercise draft and returns the complete session', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $lock = new SynchronousWorkoutSessionMutationLock;
    $useCase = new SaveExerciseProgress(
        $repository,
        new WorkoutSetCollectionFactory,
        $lock,
    );

    $result = $useCase->handle(new SaveExerciseProgressInput(
        userId: 7,
        workoutSessionId: 51,
        exerciseId: 10,
        sets: [
            new WorkoutSetInput(8, 90_000),
            new WorkoutSetInput(8, 100_000),
            new WorkoutSetInput(7, 100_000),
            new WorkoutSetInput(6, 95_000),
        ],
    ));

    expect($result->exercises)->toHaveCount(2);
    expect(array_map(
        static fn ($set): array => [$set->position, $set->repetitions, $set->workingWeightInGrams],
        $result->exercises[0]->sets,
    ))->toBe([
        [1, 8, 90_000],
        [2, 8, 100_000],
        [3, 7, 100_000],
        [4, 6, 95_000],
    ]);
    expect($repository->saveCalls)->toBe(1);
    expect($repository->find(new WorkoutSessionId(51))?->workoutExercises()[0]->workoutSets())
        ->toHaveCount(4);
    expect($lock->userIds)->toBe([7]);
});

it('allows saving a temporarily empty exercise draft', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new SaveExerciseProgress(
        $repository,
        new WorkoutSetCollectionFactory,
        new SynchronousWorkoutSessionMutationLock,
    );

    $result = $useCase->handle(new SaveExerciseProgressInput(
        userId: 7,
        workoutSessionId: 51,
        exerciseId: 10,
        sets: [],
    ));

    expect($result->exercises[0]->sets)->toBe([]);
    expect($repository->saveCalls)->toBe(1);
});

it('does not expose another users workout session', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new SaveExerciseProgress(
        $repository,
        new WorkoutSetCollectionFactory,
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new SaveExerciseProgressInput(
        userId: 8,
        workoutSessionId: 51,
        exerciseId: 10,
        sets: [new WorkoutSetInput(8, 90_000)],
    )))->toThrow(WorkoutSessionNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});
