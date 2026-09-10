<?php

namespace App\WorkoutPlanning\Application\DTO;

use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use LogicException;

final readonly class TrainingProgramDTO
{
    /** @param list<PlannedExerciseDTO> $exercises */
    public function __construct(
        public private(set) int $id,
        public private(set) int $userId,
        public private(set) int $weekday,
        public private(set) string $name,
        public private(set) array $exercises,
    ) {}

    public static function fromDomain(TrainingProgram $trainingProgram): self
    {
        $id = $trainingProgram->id
            ?? throw new LogicException('Нельзя создать DTO для несохранённой программы тренировок.');

        return new self(
            id: $id->value,
            userId: $trainingProgram->userId->value,
            weekday: $trainingProgram->weekday->value,
            name: $trainingProgram->name->value,
            exercises: array_map(
                PlannedExerciseDTO::fromDomain(...),
                $trainingProgram->plannedExercises(),
            ),
        );
    }
}
