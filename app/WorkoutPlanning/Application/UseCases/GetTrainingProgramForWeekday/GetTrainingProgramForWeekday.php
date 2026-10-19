<?php

namespace App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday;

use App\WorkoutPlanning\Application\DTO\TrainingProgramDTO;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;

final readonly class GetTrainingProgramForWeekday
{
    public function __construct(
        private TrainingProgramRepository $trainingPrograms,
    ) {}

    /** @return list<TrainingProgramDTO> */
    public function handle(GetTrainingProgramForWeekdayInput $input): array
    {
        $trainingPrograms = $this->trainingPrograms->findAllForUserOnWeekday(
            new UserId($input->userId),
            Weekday::fromValue($input->weekday),
        );

        return array_map(
            static fn (TrainingProgram $trainingProgram): TrainingProgramDTO => TrainingProgramDTO::fromDomain($trainingProgram),
            $trainingPrograms,
        );
    }
}
