<?php

use App\WorkoutPlanning\Application\Gateways\ExerciseCatalog;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Infrastructure\Locks\RedisTrainingProgramMutationLock;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Gateways\EloquentExerciseCatalog;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Repositories\EloquentTrainingProgramRepository;

it('binds workout planning ports to infrastructure adapters', function (): void {
    config()->set('workout-planning.mutation_lock.store', 'array');

    expect([
        app(TrainingProgramRepository::class)::class,
        app(ExerciseCatalog::class)::class,
        app(TrainingProgramMutationLock::class)::class,
    ])->toBe([
        EloquentTrainingProgramRepository::class,
        EloquentExerciseCatalog::class,
        RedisTrainingProgramMutationLock::class,
    ]);
});
