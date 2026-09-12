<?php

namespace App\WorkoutExecution\Application\UseCases\SkipExercise;

use App\WorkoutExecution\Application\DTO\WorkoutSessionDTO;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;

final readonly class SkipExercise
{
    public function __construct(
        private WorkoutSessionRepository $workoutSessions,
        private WorkoutSessionMutationLock $mutationLock,
    ) {}

    public function handle(SkipExerciseInput $input): WorkoutSessionDTO
    {
        $userId = new UserId($input->userId);

        return $this->mutationLock->execute(
            $userId,
            function () use ($input, $userId): WorkoutSessionDTO {
                $session = $this->workoutSessions->findForUser(
                    new WorkoutSessionId($input->workoutSessionId),
                    $userId,
                );

                if ($session === null) {
                    throw new WorkoutSessionNotFound;
                }

                $session->skipExercise(new ExerciseId($input->exerciseId));
                $this->workoutSessions->save($session);

                return WorkoutSessionDTO::fromDomain($session);
            },
        );
    }
}
