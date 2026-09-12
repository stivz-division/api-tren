<?php

namespace App\WorkoutExecution\Infrastructure\Providers;

use App\WorkoutExecution\Application\Gateways\TrainingProgramSnapshotProvider;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning\EloquentTrainingProgramSnapshotProvider;
use App\WorkoutExecution\Infrastructure\Locks\RedisWorkoutSessionMutationLock;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutSessionRepository;
use App\WorkoutExecution\Infrastructure\Time\MoscowWorkoutClock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class WorkoutExecutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            WorkoutSessionRepository::class,
            EloquentWorkoutSessionRepository::class,
        );
        $this->app->bind(
            TrainingProgramSnapshotProvider::class,
            EloquentTrainingProgramSnapshotProvider::class,
        );
        $this->app->singleton(WorkoutClock::class, MoscowWorkoutClock::class);
        $this->app->singleton(
            WorkoutSessionMutationLock::class,
            static function (Application $application): RedisWorkoutSessionMutationLock {
                $storeName = (string) config('workout-execution.mutation_lock.store', 'redis');
                $store = $application->make(CacheFactory::class)
                    ->store($storeName)
                    ->getStore();

                if (! $store instanceof LockProvider) {
                    throw new LogicException(sprintf(
                        'Хранилище кеша "%s" не поддерживает атомарные блокировки.',
                        $storeName,
                    ));
                }

                return new RedisWorkoutSessionMutationLock(
                    $store,
                    (int) config('workout-execution.mutation_lock.lock_seconds', 60),
                    (int) config('workout-execution.mutation_lock.wait_seconds', 3),
                );
            },
        );
    }
}
