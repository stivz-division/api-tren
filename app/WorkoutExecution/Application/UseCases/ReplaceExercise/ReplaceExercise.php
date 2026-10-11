<?php

namespace App\WorkoutExecution\Application\UseCases\ReplaceExercise;

use App\WorkoutExecution\Application\DTO\WorkoutSessionDTO;
use App\WorkoutExecution\Application\Exceptions\WorkoutReplacementCandidateNotFound;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\Factories\WorkoutSetCollectionFactory;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\Gateways\WorkoutReplacementCatalog;
use App\WorkoutExecution\Application\Gateways\WorkoutReplacementPlanGateway;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;

final readonly class ReplaceExercise
{
    public function __construct(
        private WorkoutSessionRepository $workoutSessions,
        private WorkoutSetCollectionFactory $setCollectionFactory,
        private WorkoutSessionMutationLock $mutationLock,
        private WorkoutReplacementCatalog $catalog,
        private WorkoutReplacementPlanGateway $planGateway,
        private WorkoutClock $clock,
    ) {}

    public function handle(ReplaceExerciseInput $input): WorkoutSessionDTO
    {
        $userId = new UserId($input->userId);

        return $this->mutationLock->execute($userId, function () use ($input, $userId): WorkoutSessionDTO {
            $session = $this->workoutSessions->findForUser(new WorkoutSessionId($input->workoutSessionId), $userId)
                ?? throw new WorkoutSessionNotFound;
            $sourceId = new ExerciseId($input->exerciseId);
            $replacementId = new ExerciseId($input->replacementExerciseId);
            $sets = $this->setCollectionFactory->create($input->sets);

            if ($session->isReplacementReplay($sourceId, $input->requestId, $replacementId, $input->replaceInPlan, $sets)) {
                return WorkoutSessionDTO::fromDomain($session);
            }

            $session->assertCanReplaceExercise($sourceId, $replacementId);
            $name = $this->catalog->findName($replacementId) ?? throw new WorkoutReplacementCandidateNotFound;
            $exercise = null;
            foreach ($session->workoutExercises() as $candidate) {
                if ($candidate->snapshot->exerciseId->value === $sourceId->value) {
                    $exercise = $candidate;
                    break;
                }
            }
            $exercise ??= throw new WorkoutSessionNotFound;
            $now = $this->clock->now();
            $replacement = new WorkoutExerciseReplacement(
                count($exercise->replacements()) + 1,
                $input->requestId,
                new ExerciseSnapshot($replacementId, $name, $exercise->snapshot->position),
                $sets,
                $input->replaceInPlan,
                $now,
            );
            $revision = $input->replaceInPlan
                ? $this->planGateway->replace($session, $exercise, $replacementId, $sets, $now)
                : null;
            $session->replaceExercise($sourceId, new WorkoutExerciseReplacement(
                $replacement->sequence,
                $replacement->requestId,
                $replacement->snapshot,
                $sets,
                $input->replaceInPlan,
                $now,
                $revision,
            ));
            $this->workoutSessions->save($session);

            return WorkoutSessionDTO::fromDomain($session);
        });
    }
}
