<?php

namespace App\WorkoutExecution\Application\DTO;

use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use DateTimeImmutable;
use LogicException;

final readonly class WorkoutSessionDTO
{
    /** @param non-empty-list<WorkoutExerciseDTO> $exercises */
    public function __construct(
        public private(set) int $id,
        public private(set) int $userId,
        public private(set) int $trainingProgramId,
        public private(set) string $programName,
        public private(set) int $scheduledWeekday,
        public private(set) string $status,
        public private(set) DateTimeImmutable $startedAt,
        public private(set) ?DateTimeImmutable $completedAt,
        public private(set) ?DateTimeImmutable $cancelledAt,
        public private(set) array $exercises,
    ) {}

    public static function fromDomain(WorkoutSession $session): self
    {
        $id = $session->id
            ?? throw new LogicException('Нельзя создать DTO для несохранённой тренировочной сессии.');

        return new self(
            id: $id->value,
            userId: $session->userId->value,
            trainingProgramId: $session->programSnapshot->trainingProgramId->value,
            programName: $session->programSnapshot->name->value,
            scheduledWeekday: $session->programSnapshot->scheduledWeekday->value,
            status: $session->status->value,
            startedAt: $session->startedAt,
            completedAt: $session->completedAt,
            cancelledAt: $session->cancelledAt,
            exercises: array_map(
                WorkoutExerciseDTO::fromDomain(...),
                $session->workoutExercises(),
            ),
        );
    }
}
