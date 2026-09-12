<?php

use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\UseCases\ReopenExercise\ReopenExercise;
use App\WorkoutExecution\Application\UseCases\ReopenExercise\ReopenExerciseInput;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('reopens a skipped exercise with fresh sets from its plan', function () {
    $session = WorkoutSessionFixture::active();
    $session->skipExercise(new ExerciseId(10));
    $repository = new InMemoryWorkoutSessionRepository(52, $session);
    $lock = new SynchronousWorkoutSessionMutationLock;
    $useCase = new ReopenExercise($repository, $lock);

    $result = $useCase->handle(new ReopenExerciseInput(7, 51, 10));

    expect($result->exercises[0]->status)->toBe('pending');
    expect(array_map(
        static fn ($set): array => [$set->position, $set->repetitions, $set->workingWeightInGrams],
        $result->exercises[0]->sets,
    ))->toBe([
        [1, 8, 90_000],
        [2, 8, 90_000],
        [3, 8, 90_000],
    ]);
    expect($repository->saveCalls)->toBe(1);
    expect($lock->userIds)->toBe([7]);
});

it('does not expose another users workout session', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new ReopenExercise($repository, new SynchronousWorkoutSessionMutationLock);

    expect(fn () => $useCase->handle(new ReopenExerciseInput(8, 51, 10)))
        ->toThrow(WorkoutSessionNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});
