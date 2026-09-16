<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

interface CompletedWorkoutProvider
{
    /** Читает согласованный исторический снимок сессии; чужая или отсутствующая сессия даёт null. */
    public function findForUser(WorkoutSessionId $sessionId, UserId $userId): ?CompletedWorkoutData;
}
