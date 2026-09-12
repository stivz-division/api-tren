<?php

namespace App\WorkoutPlanning\Application\DTO;

use App\WorkoutPlanning\Domain\Entities\PlannedExercise;

final readonly class PlannedExerciseDTO
{
    /** @param list<PlannedSetDTO> $sets */
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) array $sets,
        public private(set) int $position,
    ) {}

    public static function fromDomain(PlannedExercise $plannedExercise): self
    {
        return new self(
            exerciseId: $plannedExercise->exerciseId->value,
            sets: array_map(PlannedSetDTO::fromDomain(...), $plannedExercise->plannedSets()),
            position: $plannedExercise->position->value,
        );
    }
}
