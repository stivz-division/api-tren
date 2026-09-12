<?php

namespace App\WorkoutExecution\Application\UseCases\StartWorkoutSession;

use App\WorkoutExecution\Application\DTO\WorkoutSessionDTO;
use App\WorkoutExecution\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutExecution\Application\Factories\WorkoutSessionFactory;
use App\WorkoutExecution\Application\Gateways\TrainingProgramSnapshotProvider;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\Exceptions\ActiveWorkoutSessionAlreadyExists;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;

final readonly class StartWorkoutSession
{
    public function __construct(
        private WorkoutSessionRepository $workoutSessions,
        private TrainingProgramSnapshotProvider $programSnapshots,
        private WorkoutSessionFactory $sessionFactory,
        private WorkoutClock $clock,
        private WorkoutSessionMutationLock $mutationLock,
    ) {}

    public function handle(StartWorkoutSessionInput $input): WorkoutSessionDTO
    {
        $userId = new UserId($input->userId);
        $trainingProgramId = new TrainingProgramId($input->trainingProgramId);

        return $this->mutationLock->execute(
            $userId,
            function () use ($userId, $trainingProgramId): WorkoutSessionDTO {
                $activeSession = $this->workoutSessions->findActiveForUser($userId);

                if ($activeSession !== null) {
                    if (
                        $activeSession->programSnapshot->trainingProgramId->value
                        === $trainingProgramId->value
                    ) {
                        return WorkoutSessionDTO::fromDomain($activeSession);
                    }

                    throw new ActiveWorkoutSessionAlreadyExists($userId);
                }

                $programSnapshot = $this->programSnapshots->findForUser(
                    $trainingProgramId,
                    $userId,
                );

                if ($programSnapshot === null) {
                    throw new TrainingProgramNotFound;
                }

                $session = $this->sessionFactory->create(
                    $userId,
                    $programSnapshot,
                    $this->clock->now(),
                );

                return WorkoutSessionDTO::fromDomain(
                    $this->workoutSessions->add($session),
                );
            },
        );
    }
}
