<?php

namespace App\WorkoutPlanning\Infrastructure\Locks;

use App\WorkoutPlanning\Application\Exceptions\TrainingProgramMutationInProgress;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use InvalidArgumentException;

final readonly class RedisTrainingProgramMutationLock implements TrainingProgramMutationLock
{
    public function __construct(
        private LockProvider $locks,
        private int $lockSeconds,
        private int $waitSeconds,
    ) {
        if ($this->lockSeconds < 1) {
            throw new InvalidArgumentException(
                'Время действия блокировки должно быть положительным.',
            );
        }

        if ($this->waitSeconds < 0) {
            throw new InvalidArgumentException(
                'Время ожидания блокировки не может быть отрицательным.',
            );
        }
    }

    public function execute(UserId $userId, Closure $callback): mixed
    {
        $lock = $this->locks->lock(
            'workout-planning:user:'.$userId->value,
            $this->lockSeconds,
        );

        try {
            $lock->block($this->waitSeconds);
        } catch (LockTimeoutException $exception) {
            throw new TrainingProgramMutationInProgress($exception);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
