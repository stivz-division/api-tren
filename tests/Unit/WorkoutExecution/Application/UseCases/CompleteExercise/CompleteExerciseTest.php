<?php

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\Factories\WorkoutSetCollectionFactory;
use App\WorkoutExecution\Application\UseCases\CompleteExercise\CompleteExercise;
use App\WorkoutExecution\Application\UseCases\CompleteExercise\CompleteExerciseInput;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseHasNoSets;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('atomically saves the final sets and completes the exercise', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $lock = new SynchronousWorkoutSessionMutationLock;
    $useCase = new CompleteExercise(
        $repository,
        new WorkoutSetCollectionFactory,
        $lock,
    );

    $result = $useCase->handle(new CompleteExerciseInput(
        userId: 7,
        workoutSessionId: 51,
        exerciseId: 10,
        sets: [
            new WorkoutSetInput(8, 90_000),
            new WorkoutSetInput(8, 100_000),
            new WorkoutSetInput(7, 100_000),
        ],
    ));

    expect($result->exercises[0]->status)->toBe('completed');
    expect($result->exercises[0]->sets[1]->workingWeightInGrams)->toBe(100_000);
    expect($result->exercises[1]->status)->toBe('pending');
    expect($repository->saveCalls)->toBe(1);
    expect($repository->find(new WorkoutSessionId(51))?->workoutExercises()[0]->status->value)
        ->toBe('completed');
    expect($lock->userIds)->toBe([7]);
});

it('does not persist completion when the final set list is empty', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new CompleteExercise(
        $repository,
        new WorkoutSetCollectionFactory,
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new CompleteExerciseInput(
        userId: 7,
        workoutSessionId: 51,
        exerciseId: 10,
        sets: [],
    )))->toThrow(WorkoutExerciseHasNoSets::class);
    expect($repository->saveCalls)->toBe(0);
    expect($repository->find(new WorkoutSessionId(51))?->workoutExercises()[0]->workoutSets())
        ->toHaveCount(3);
});

it('does not expose another users workout session', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new CompleteExercise(
        $repository,
        new WorkoutSetCollectionFactory,
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new CompleteExerciseInput(
        userId: 8,
        workoutSessionId: 51,
        exerciseId: 10,
        sets: [new WorkoutSetInput(8, 90_000)],
    )))->toThrow(WorkoutSessionNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});
