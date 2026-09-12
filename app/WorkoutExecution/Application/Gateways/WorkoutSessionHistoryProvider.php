<?php

namespace App\WorkoutExecution\Application\Gateways;

use App\WorkoutExecution\Application\DTO\WorkoutSessionHistoryPageDTO;
use App\WorkoutExecution\Domain\ValueObjects\UserId;

interface WorkoutSessionHistoryProvider
{
    public function paginateForUser(
        UserId $userId,
        int $perPage,
        ?string $cursor,
    ): WorkoutSessionHistoryPageDTO;
}
