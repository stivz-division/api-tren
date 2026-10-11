<?php

namespace App\WorkoutExecution\Application\DTO;

use App\WorkoutExecution\Domain\Entities\WorkoutExercise;

final readonly class EffectiveWorkoutExerciseDTO
{
    /** @param list<WorkoutSetDTO> $plannedSets */
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) string $name,
        public private(set) array $plannedSets,
    ) {}

    public static function fromDomain(WorkoutExercise $exercise): self
    {
        $snapshot = $exercise->effectiveSnapshot();

        return new self(
            exerciseId: $snapshot->exerciseId->value,
            name: $snapshot->name->value,
            plannedSets: array_map(WorkoutSetDTO::fromDomain(...), $exercise->effectivePlannedSets()),
        );
    }
}
