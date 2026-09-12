<?php

namespace App\WorkoutExecution\Application\UseCases\GetActiveWorkoutSession;

use App\WorkoutExecution\Application\DTO\WorkoutSessionDTO;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Domain\ValueObjects\UserId;

final readonly class GetActiveWorkoutSession
{
    public function __construct(private WorkoutSessionRepository $workoutSessions) {}

    public function handle(GetActiveWorkoutSessionInput $input): ?WorkoutSessionDTO
    {
        $session = $this->workoutSessions->findActiveForUser(new UserId($input->userId));

        return $session === null ? null : WorkoutSessionDTO::fromDomain($session);
    }
}
