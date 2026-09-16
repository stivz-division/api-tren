<?php

namespace App\WorkoutExecution\Application\Gateways;

use App\WorkoutExecution\Domain\ValueObjects\UserId;
use Closure;

interface WorkoutCompletionTransaction
{
    /**
     * Атомарно сохраняет завершение и результаты синхронных уведомлений.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function execute(UserId $userId, Closure $callback): mixed;
}
