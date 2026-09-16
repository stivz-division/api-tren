<?php

namespace App\WorkoutExecution\Infrastructure\Transactions;

use App\Models\User;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\Gateways\WorkoutCompletionTransaction;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use Closure;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseWorkoutCompletionTransaction implements WorkoutCompletionTransaction
{
    public function __construct(private DatabaseManager $database) {}

    public function execute(UserId $userId, Closure $callback): mixed
    {
        return $this->database->connection()->transaction(function () use ($userId, $callback): mixed {
            if (User::query()->whereKey($userId->value)->lockForUpdate()->first() === null) {
                throw new WorkoutSessionNotFound;
            }

            return $callback();
        }, 1);
    }
}
