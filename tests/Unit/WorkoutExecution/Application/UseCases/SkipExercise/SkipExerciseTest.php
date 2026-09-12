<?php

use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\UseCases\SkipExercise\SkipExercise;
use App\WorkoutExecution\Application\UseCases\SkipExercise\SkipExerciseInput;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('clears the exercise draft and marks it as skipped', function () {
    $session = WorkoutSessionFixture::active();
    $session->saveExerciseProgress(
        new ExerciseId(10),
        new WorkoutSetCollection(new WorkoutSet(
            new SetPosition(1),
            new Repetitions(5),
            new WorkingWeight(110_000),
        )),
    );
    $repository = new InMemoryWorkoutSessionRepository(52, $session);
    $lock = new SynchronousWorkoutSessionMutationLock;
    $useCase = new SkipExercise($repository, $lock);

    $result = $useCase->handle(new SkipExerciseInput(7, 51, 10));

    expect($result->exercises[0]->status)->toBe('skipped');
    expect($result->exercises[0]->sets)->toBe([]);
    expect($repository->saveCalls)->toBe(1);
    expect($lock->userIds)->toBe([7]);
});

it('does not expose another users workout session', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new SkipExercise($repository, new SynchronousWorkoutSessionMutationLock);

    expect(fn () => $useCase->handle(new SkipExerciseInput(8, 51, 10)))
        ->toThrow(WorkoutSessionNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});
