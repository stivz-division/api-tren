<?php

use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession\CompleteWorkoutSession;
use App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession\CompleteWorkoutSessionInput;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('completes a resolved session at server time', function () {
    $session = WorkoutSessionFixture::active();
    $exercise = $session->workoutExercises()[0];
    $session->completeExercise(
        new ExerciseId(10),
        new WorkoutSetCollection(...$exercise->workoutSets()),
    );
    $session->skipExercise(new ExerciseId(20));
    $completedAt = new DateTimeImmutable('2026-09-15 20:15:00', new DateTimeZone('Europe/Moscow'));
    $repository = new InMemoryWorkoutSessionRepository(52, $session);
    $lock = new SynchronousWorkoutSessionMutationLock;
    $useCase = new CompleteWorkoutSession(
        $repository,
        new FrozenWorkoutClock($completedAt),
        $lock,
    );

    $result = $useCase->handle(new CompleteWorkoutSessionInput(7, 51));

    expect($result->status)->toBe('completed');
    expect($result->completedAt)->toEqual($completedAt);
    expect($repository->saveCalls)->toBe(1);
    expect($repository->find(new WorkoutSessionId(51))?->completedAt)->toEqual($completedAt);
    expect($lock->userIds)->toBe([7]);
});

it('does not expose another users workout session', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new CompleteWorkoutSession(
        $repository,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-15 20:15:00')),
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new CompleteWorkoutSessionInput(8, 51)))
        ->toThrow(WorkoutSessionNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});
