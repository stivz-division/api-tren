<?php

namespace App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram;

use App\WorkoutPlanning\Application\DTO\TrainingProgramDTO;
use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Application\Factories\PlannedExerciseCollectionFactory;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;

final readonly class UpdateTrainingProgram
{
    public function __construct(
        private TrainingProgramRepository $trainingPrograms,
        private PlannedExerciseCollectionFactory $exerciseCollectionFactory,
        private TrainingProgramMutationLock $mutationLock,
    ) {}

    public function handle(UpdateTrainingProgramInput $input): TrainingProgramDTO
    {
        $userId = new UserId($input->userId);

        return $this->mutationLock->execute(
            $userId,
            function () use ($input, $userId): TrainingProgramDTO {
                $trainingProgram = $this->trainingPrograms->findForUser(
                    new TrainingProgramId($input->trainingProgramId),
                    $userId,
                );

                if ($trainingProgram === null) {
                    throw new TrainingProgramNotFound;
                }

                $name = new ProgramName($input->name);
                $exercises = $this->exerciseCollectionFactory->create($input->exercises);

                $trainingProgram->rename($name);
                $trainingProgram->replaceExercises($exercises);

                $this->trainingPrograms->save($trainingProgram);

                return TrainingProgramDTO::fromDomain($trainingProgram);
            },
        );
    }
}
