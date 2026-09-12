<?php

namespace App\WorkoutExecution\Application\UseCases\GetActiveWorkoutSession;

final readonly class GetActiveWorkoutSessionInput
{
    public function __construct(public private(set) int $userId) {}
}
