<?php

namespace App\WorkoutPlanning\Application\UseCases\GetTrainingPrograms;

use App\WorkoutPlanning\Application\DTO\TrainingProgramDTO;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;

final readonly class GetTrainingPrograms
{
    public function __construct(private TrainingProgramRepository $trainingPrograms) {}

    /** @return list<TrainingProgramDTO> */
    public function handle(GetTrainingProgramsInput $input): array
    {
        return array_map(
            static fn (TrainingProgram $trainingProgram): TrainingProgramDTO => TrainingProgramDTO::fromDomain(
                $trainingProgram,
            ),
            $this->trainingPrograms->findAllForUser(new UserId($input->userId)),
        );
    }
}
