<?php

namespace App\WorkoutExecution\Application\Gateways;

use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;

interface WorkoutCompletionNotifier
{
    /** Выполняется синхронно в транзакции завершения; ошибка отменяет завершение. */
    public function completed(WorkoutSessionId $sessionId, UserId $userId): void;
}
