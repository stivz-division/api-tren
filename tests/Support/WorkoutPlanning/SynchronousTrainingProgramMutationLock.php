<?php

namespace Tests\Support\WorkoutPlanning;

use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use Closure;

final class SynchronousTrainingProgramMutationLock implements TrainingProgramMutationLock
{
    /** @var list<int> */
    public private(set) array $userIds = [];

    public function execute(UserId $userId, Closure $callback): mixed
    {
        $this->userIds[] = $userId->value;

        return $callback();
    }
}
