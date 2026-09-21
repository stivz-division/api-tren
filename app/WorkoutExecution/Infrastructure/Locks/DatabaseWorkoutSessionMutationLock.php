<?php

namespace App\WorkoutExecution\Infrastructure\Locks;

use App\Models\User;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use Closure;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseWorkoutSessionMutationLock implements WorkoutSessionMutationLock
{
    public function __construct(private RedisWorkoutSessionMutationLock $redisLock, private DatabaseManager $database) {}

    public function execute(UserId $userId, Closure $callback): mixed
    {
        return $this->redisLock->execute($userId, fn (): mixed => $this->database->transaction(function () use ($userId, $callback): mixed {
            User::query()->whereKey($userId->value)->lockForUpdate()->firstOrFail();

            return $callback();
        }));
    }
}
