<?php

namespace Tests\Support\WorkoutExecution;

use App\WorkoutExecution\Application\Gateways\WorkoutCompletionNotifier;
use App\WorkoutExecution\Application\Gateways\WorkoutCompletionTransaction;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use Closure;

final class InMemoryWorkoutCompletion implements WorkoutCompletionNotifier, WorkoutCompletionTransaction
{
    /** @var list<int> */
    public array $sessionIds = [];

    public function execute(UserId $userId, Closure $callback): mixed
    {
        return $callback();
    }

    public function completed(WorkoutSessionId $sessionId, UserId $userId): void
    {
        $this->sessionIds[] = $sessionId->value;
    }
}
