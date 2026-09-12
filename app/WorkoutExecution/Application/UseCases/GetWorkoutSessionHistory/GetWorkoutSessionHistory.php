<?php

namespace App\WorkoutExecution\Application\UseCases\GetWorkoutSessionHistory;

use App\WorkoutExecution\Application\DTO\WorkoutSessionHistoryPageDTO;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionHistoryProvider;
use App\WorkoutExecution\Domain\ValueObjects\UserId;

final readonly class GetWorkoutSessionHistory
{
    public function __construct(private WorkoutSessionHistoryProvider $history) {}

    public function handle(GetWorkoutSessionHistoryInput $input): WorkoutSessionHistoryPageDTO
    {
        return $this->history->paginateForUser(
            new UserId($input->userId),
            $input->perPage,
            $input->cursor,
        );
    }
}
