<?php

namespace App\WorkoutAnalysis\Infrastructure\Transactions;

use App\Models\User;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use Closure;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseAnalysisTransaction implements AnalysisTransaction
{
    public function __construct(private DatabaseManager $database) {}

    public function execute(UserId $userId, Closure $callback): mixed
    {
        return $this->database->connection()->transaction(function () use ($userId, $callback): mixed {
            if (User::query()->whereKey($userId->value)->lockForUpdate()->first() === null) {
                throw new WorkoutAnalysisNotFound;
            }

            return $callback();
        }, 1);
    }

    public function afterCommit(Closure $callback): void
    {
        $this->database->connection()->afterCommit($callback);
    }
}
