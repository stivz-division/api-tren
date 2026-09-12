<?php

namespace App\WorkoutExecution\Application\DTO;

use App\WorkoutExecution\Domain\Entities\WorkoutExercise;

final readonly class WorkoutExerciseDTO
{
    /**
     * @param  list<WorkoutSetDTO>  $plannedSets
     * @param  list<WorkoutSetDTO>  $sets
     */
    public function __construct(
        public private(set) int $exerciseId,
        public private(set) string $name,
        public private(set) int $position,
        public private(set) array $plannedSets,
        public private(set) string $status,
        public private(set) array $sets,
    ) {}

    public static function fromDomain(WorkoutExercise $exercise): self
    {
        return new self(
            exerciseId: $exercise->snapshot->exerciseId->value,
            name: $exercise->snapshot->name->value,
            position: $exercise->snapshot->position->value,
            plannedSets: array_map(WorkoutSetDTO::fromDomain(...), $exercise->plannedSets()),
            status: $exercise->status->value,
            sets: array_map(WorkoutSetDTO::fromDomain(...), $exercise->workoutSets()),
        );
    }
}
