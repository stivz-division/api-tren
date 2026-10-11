<?php

namespace App\WorkoutExecution\Application\Gateways;

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use DateTimeImmutable;

interface WorkoutReplacementPlanGateway
{
    public function replace(
        WorkoutSession $session,
        WorkoutExercise $exercise,
        ExerciseId $replacementId,
        WorkoutSetCollection $sets,
        DateTimeImmutable $now,
    ): int;
}
