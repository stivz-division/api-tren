<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAIAnalysis;

use App\WorkoutAnalysis\Application\DTO\AIAnalysisTask;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AIAnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAIAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

final readonly class RecoverWorkoutAIAnalysis
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AIAnalysisTaskScheduler $scheduler,
        private AIExecutionPolicy $policy,
    ) {}

    /** Служебная операция для одной сессии; кандидатов выбирает адаптер команды восстановления. */
    public function handle(RecoverWorkoutAIAnalysisInput $input): ?WorkoutAIAnalysis
    {
        $userId = new UserId($input->userId);
        $sessionId = new WorkoutSessionId($input->workoutSessionId);

        return $this->transaction->execute($userId, function () use ($userId, $sessionId): ?WorkoutAIAnalysis {
            $analysis = $this->analyses->findForSession($sessionId, $userId) ?? throw new WorkoutAnalysisNotFound;
            if ($analysis->ai() === null) {
                return null;
            }
            $now = $this->clock->now();
            $attempt = $analysis->ai()->currentAttempt();
            $dispatch = $this->policy->pendingNeedsDispatch($attempt, $now);

            if ($analysis->recoverExpiredAIAttempt(
                $now,
                $this->policy->retryAt($attempt, AnalysisFailureCode::AttemptTimedOut, $now),
            )) {
                $this->analyses->save($analysis);
                $dispatch = $analysis->ai()?->status() === AnalysisStatus::Pending;
            }

            if ($dispatch) {
                $task = AIAnalysisTask::fromDomain($analysis);
                $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
            }

            return $analysis->ai();
        });
    }
}
