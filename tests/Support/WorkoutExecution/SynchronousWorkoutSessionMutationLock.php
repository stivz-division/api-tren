<?php

namespace Tests\Support\WorkoutExecution;

use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use Closure;

final class SynchronousWorkoutSessionMutationLock implements WorkoutSessionMutationLock
{
    /** @var list<int> */
    public private(set) array $userIds = [];

    public function execute(UserId $userId, Closure $callback): mixed
    {
        $this->userIds[] = $userId->value;

        return $callback();
    }
}
