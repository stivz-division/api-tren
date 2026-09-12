<?php

use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\UseCases\CancelWorkoutSession\CancelWorkoutSession;
use App\WorkoutExecution\Application\UseCases\CancelWorkoutSession\CancelWorkoutSessionInput;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('cancels an active session at server time', function () {
    $cancelledAt = new DateTimeImmutable('2026-09-15 19:20:00', new DateTimeZone('Europe/Moscow'));
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $lock = new SynchronousWorkoutSessionMutationLock;
    $useCase = new CancelWorkoutSession(
        $repository,
        new FrozenWorkoutClock($cancelledAt),
        $lock,
    );

    $result = $useCase->handle(new CancelWorkoutSessionInput(7, 51));

    expect($result->status)->toBe('cancelled');
    expect($result->cancelledAt)->toEqual($cancelledAt);
    expect($repository->saveCalls)->toBe(1);
    expect($repository->find(new WorkoutSessionId(51))?->cancelledAt)->toEqual($cancelledAt);
    expect($lock->userIds)->toBe([7]);
});

it('does not expose another users workout session', function () {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $useCase = new CancelWorkoutSession(
        $repository,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-15 19:20:00')),
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new CancelWorkoutSessionInput(8, 51)))
        ->toThrow(WorkoutSessionNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});
