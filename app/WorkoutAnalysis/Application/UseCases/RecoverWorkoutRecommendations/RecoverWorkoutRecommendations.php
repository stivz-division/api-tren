<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutRecommendations;

use App\WorkoutAnalysis\Application\DTO\RecommendationTask;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\RecommendationTaskScheduler;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Domain\Entities\WorkoutRecommendationGeneration;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

final readonly class RecoverWorkoutRecommendations
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private RecommendationTaskScheduler $scheduler,
        private AIExecutionPolicy $policy,
    ) {}

    /** Служебная операция для одной сессии; кандидатов выбирает адаптер команды восстановления. */
    public function handle(RecoverWorkoutRecommendationsInput $input): ?WorkoutRecommendationGeneration
    {
        $userId = new UserId($input->userId);
        $sessionId = new WorkoutSessionId($input->workoutSessionId);

        return $this->transaction->execute($userId, function () use ($userId, $sessionId): ?WorkoutRecommendationGeneration {
            $analysis = $this->analyses->findForSession($sessionId, $userId) ?? throw new WorkoutAnalysisNotFound;
            if ($analysis->recommendations() === null) {
                return null;
            }
            $now = $this->clock->now();
            $attempt = $analysis->recommendations()->currentAttempt();
            $dispatch = $this->policy->pendingNeedsDispatch($attempt, $now);

            if ($analysis->recoverExpiredRecommendationAttempt(
                $now,
                $this->policy->retryAt($attempt, AnalysisFailureCode::AttemptTimedOut, $now),
            )) {
                $this->analyses->save($analysis);
                $dispatch = $analysis->recommendations()?->status() === AnalysisStatus::Pending;
            }

            if ($dispatch) {
                $task = RecommendationTask::fromDomain($analysis);
                $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
            }

            return $analysis->recommendations();
        });
    }
}
