<?php

namespace App\WorkoutPlanning\Application\Gateways;

use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use Closure;

interface TrainingProgramMutationLock
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function execute(UserId $userId, Closure $callback): mixed;
}
