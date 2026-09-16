<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use DateTimeImmutable;

final readonly class WorkoutDeviationResultDTO
{
    /** @param list<ExerciseDeviationDTO> $exercises */
    public function __construct(
        public private(set) int $trainingProgramId,
        public private(set) string $programName,
        public private(set) DateTimeImmutable $workoutCompletedAt,
        public private(set) int $completedExercises,
        public private(set) int $skippedExercises,
        public private(set) MetricDeviationDTO $sets,
        public private(set) MetricDeviationDTO $repetitions,
        public private(set) MetricDeviationDTO $volume,
        public private(set) array $exercises,
    ) {}

    public static function fromDomain(WorkoutDeviationResult $value): self
    {
        return new self(
            $value->snapshot->trainingProgramId->value,
            $value->snapshot->programName->value,
            $value->snapshot->completedAt,
            $value->completedExercises,
            $value->skippedExercises,
            MetricDeviationDTO::fromDomain($value->sets),
            MetricDeviationDTO::fromDomain($value->repetitions),
            MetricDeviationDTO::fromDomain($value->volume),
            array_map(ExerciseDeviationDTO::fromDomain(...), $value->exercises),
        );
    }
}
