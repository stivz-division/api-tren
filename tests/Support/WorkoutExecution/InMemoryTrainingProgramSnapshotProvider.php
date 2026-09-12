<?php

namespace Tests\Support\WorkoutExecution;

use App\WorkoutExecution\Application\DTO\TrainingProgramSnapshotData;
use App\WorkoutExecution\Application\Gateways\TrainingProgramSnapshotProvider;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;

final class InMemoryTrainingProgramSnapshotProvider implements TrainingProgramSnapshotProvider
{
    /** @var array<int, TrainingProgramSnapshotData> */
    private array $programs = [];

    public private(set) int $findCalls = 0;

    public function __construct(
        private readonly int $userId,
        TrainingProgramSnapshotData ...$programs,
    ) {
        foreach ($programs as $program) {
            $this->programs[$program->trainingProgramId] = $program;
        }
    }

    public function findForUser(
        TrainingProgramId $trainingProgramId,
        UserId $userId,
    ): ?TrainingProgramSnapshotData {
        $this->findCalls++;

        if ($userId->value !== $this->userId) {
            return null;
        }

        return $this->programs[$trainingProgramId->value] ?? null;
    }
}
