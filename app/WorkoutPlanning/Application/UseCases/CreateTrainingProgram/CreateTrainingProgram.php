<?php

namespace App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram;

use App\WorkoutPlanning\Application\DTO\TrainingProgramDTO;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;

final readonly class CreateTrainingProgram
{
    public function __construct(
        private TrainingProgramRepository $trainingPrograms,
        private PlannedExerciseCollectionFactory $exerciseCollectionFactory,
        private TrainingProgramMutationLock $mutationLock,
    ) {}

    public function handle(CreateTrainingProgramInput $input): TrainingProgramDTO
    {
        $userId = new UserId($input->userId);
        $weekday = Weekday::fromValue($input->weekday);

        return $this->mutationLock->execute(
            $userId,
            function () use ($input, $userId, $weekday): TrainingProgramDTO {
                $trainingProgram = TrainingProgram::create(
                    $userId,
                    $weekday,
                    $this->exerciseCollectionFactory->create($input->exercises),
                    $input->name === null ? null : new ProgramName($input->name),
                );

                $trainingProgram = $this->trainingPrograms->add($trainingProgram);

                return TrainingProgramDTO::fromDomain($trainingProgram);
            },
        );
    }
}
