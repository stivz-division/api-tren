<?php

namespace App\WorkoutPlanning\Infrastructure\Providers;

use App\WorkoutPlanning\Application\Gateways\ExerciseCatalog;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Infrastructure\Locks\RedisTrainingProgramMutationLock;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Gateways\EloquentExerciseCatalog;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Repositories\EloquentTrainingProgramRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class WorkoutPlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TrainingProgramRepository::class,
            EloquentTrainingProgramRepository::class,
        );
        $this->app->bind(ExerciseCatalog::class, EloquentExerciseCatalog::class);
        $this->app->singleton(
            TrainingProgramMutationLock::class,
            static function (Application $application): RedisTrainingProgramMutationLock {
                $storeName = (string) config('workout-planning.mutation_lock.store', 'redis');
                $store = $application->make(CacheFactory::class)
                    ->store($storeName)
                    ->getStore();

                if (! $store instanceof LockProvider) {
                    throw new LogicException(sprintf(
                        'Хранилище кеша "%s" не поддерживает атомарные блокировки.',
                        $storeName,
                    ));
                }

                return new RedisTrainingProgramMutationLock(
                    $store,
                    (int) config('workout-planning.mutation_lock.lock_seconds', 10),
                    (int) config('workout-planning.mutation_lock.wait_seconds', 3),
                );
            },
        );
    }
}
