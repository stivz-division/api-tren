<?php

use App\WorkoutExecution\Application\Gateways\TrainingProgramSnapshotProvider;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning\EloquentTrainingProgramSnapshotProvider;
use App\WorkoutExecution\Infrastructure\Locks\RedisWorkoutSessionMutationLock;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutSessionRepository;
use App\WorkoutExecution\Infrastructure\Time\MoscowWorkoutClock;

it('binds workout execution ports to infrastructure adapters', function (): void {
    config()->set('workout-execution.mutation_lock.store', 'array');

    expect([
        app(WorkoutSessionRepository::class)::class,
        app(TrainingProgramSnapshotProvider::class)::class,
        app(WorkoutClock::class)::class,
        app(WorkoutSessionMutationLock::class)::class,
    ])->toBe([
        EloquentWorkoutSessionRepository::class,
        EloquentTrainingProgramSnapshotProvider::class,
        MoscowWorkoutClock::class,
        RedisWorkoutSessionMutationLock::class,
    ]);
});
