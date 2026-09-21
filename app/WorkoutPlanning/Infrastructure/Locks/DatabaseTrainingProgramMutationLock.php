<?php

namespace App\WorkoutPlanning\Infrastructure\Locks;

use App\Models\User;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use Closure;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseTrainingProgramMutationLock implements TrainingProgramMutationLock
{
    public function __construct(private RedisTrainingProgramMutationLock $redisLock, private DatabaseManager $database) {}

    public function execute(UserId $userId, Closure $callback): mixed
    {
        return $this->redisLock->execute($userId, fn (): mixed => $this->database->transaction(function () use ($userId, $callback): mixed {
            User::query()->whereKey($userId->value)->lockForUpdate()->firstOrFail();

            return $callback();
        }));
    }
}
