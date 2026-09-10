<?php

namespace App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday;

use App\WorkoutPlanning\Application\DTO\TrainingProgramDTO;
use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;

final readonly class GetTrainingProgramForWeekday
{
    public function __construct(
        private TrainingProgramRepository $trainingPrograms,
    ) {}

    public function handle(GetTrainingProgramForWeekdayInput $input): TrainingProgramDTO
    {
        $trainingProgram = $this->trainingPrograms->findForUserOnWeekday(
            new UserId($input->userId),
            Weekday::fromValue($input->weekday),
        );

        if ($trainingProgram === null) {
            throw new TrainingProgramNotFound;
        }

        return TrainingProgramDTO::fromDomain($trainingProgram);
    }
}
