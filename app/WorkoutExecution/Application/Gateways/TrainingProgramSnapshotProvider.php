<?php

namespace App\WorkoutExecution\Application\Gateways;

use App\WorkoutExecution\Application\DTO\TrainingProgramSnapshotData;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;

interface TrainingProgramSnapshotProvider
{
    public function findForUser(
        TrainingProgramId $trainingProgramId,
        UserId $userId,
    ): ?TrainingProgramSnapshotData;
}
