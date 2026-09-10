<?php

namespace App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram;

use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;

final readonly class DeleteTrainingProgram
{
    public function __construct(
        private TrainingProgramRepository $trainingPrograms,
        private TrainingProgramMutationLock $mutationLock,
    ) {}

    public function handle(DeleteTrainingProgramInput $input): void
    {
        $userId = new UserId($input->userId);

        $this->mutationLock->execute(
            $userId,
            function () use ($input, $userId): void {
                $trainingProgram = $this->trainingPrograms->findForUser(
                    new TrainingProgramId($input->trainingProgramId),
                    $userId,
                );

                if ($trainingProgram === null) {
                    throw new TrainingProgramNotFound;
                }

                $this->trainingPrograms->delete($trainingProgram);
            },
        );
    }
}
