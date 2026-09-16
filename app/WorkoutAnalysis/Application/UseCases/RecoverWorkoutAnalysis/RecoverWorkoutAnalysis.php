<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAnalysis;

use App\WorkoutAnalysis\Application\DTO\DeviationTask;
use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Policies\AnalysisExecutionPolicy;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysis;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

final readonly class RecoverWorkoutAnalysis
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AnalysisTaskScheduler $scheduler,
        private AnalysisExecutionPolicy $policy,
        private InitializeWorkoutAnalysis $initialize,
    ) {}

    /** Служебная операция для одной сессии; кандидатов выбирает адаптер команды восстановления. */
    public function handle(RecoverWorkoutAnalysisInput $input): WorkoutDeviationAnalysisDTO
    {
        $userId = new UserId($input->userId);
        $sessionId = new WorkoutSessionId($input->workoutSessionId);

        if ($this->analyses->findForSession($sessionId, $userId) === null) {
            return $this->initialize->handle(new InitializeWorkoutAnalysisInput($input->userId, $input->workoutSessionId));
        }

        return $this->transaction->execute($userId, function () use ($userId, $sessionId): WorkoutDeviationAnalysisDTO {
            $analysis = $this->analyses->findForSession($sessionId, $userId) ?? throw new WorkoutAnalysisNotFound;
            $now = $this->clock->now();
            $attempt = $analysis->deviations()->currentAttempt();
            $dispatch = $this->policy->pendingNeedsDispatch($attempt, $now);

            if ($analysis->recoverExpiredDeviationAttempt(
                $now,
                $this->policy->retryAt($attempt, AnalysisFailureCode::AttemptTimedOut, $now),
            )) {
                $this->analyses->save($analysis);
                $dispatch = $analysis->deviations()->status() === AnalysisStatus::Pending;
            }

            if ($dispatch) {
                $task = DeviationTask::fromDomain($analysis);
                $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
            }

            return WorkoutDeviationAnalysisDTO::fromDomain($analysis);
        });
    }
}
