<?php

namespace App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations;

use App\WorkoutAnalysis\Application\DTO\AIAnalysisTask;
use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AIAnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Policies\AnalysisExecutionPolicy;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutDeviationFailure\RecordWorkoutDeviationFailure;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutDeviationFailure\RecordWorkoutDeviationFailureInput;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use OverflowException;
use Throwable;

final readonly class CalculateWorkoutDeviations
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private WorkoutDeviationCalculator $calculator,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AnalysisExecutionPolicy $policy,
        private RecordWorkoutDeviationFailure $failures,
        private AIAnalysisTaskScheduler $aiScheduler,
    ) {}

    /** Точка входа worker: вызывается без внешней транзакции, чтобы зафиксировать захват до расчёта. */
    public function handle(CalculateWorkoutDeviationsInput $input): WorkoutDeviationAnalysisDTO
    {
        $userId = new UserId($input->userId);
        $analysisId = new WorkoutAnalysisId($input->analysisId);
        $snapshot = $this->transaction->execute($userId, function () use ($input, $userId, $analysisId): ?CompletedWorkoutSnapshot {
            $analysis = $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound;
            $now = $this->clock->now();

            if (! $analysis->startDeviationAttempt($input->attemptNumber, $now, $this->policy->expiresAt($now))) {
                return null;
            }

            $this->analyses->save($analysis);

            return $analysis->deviations()->snapshot;
        });

        if ($snapshot === null) {
            return WorkoutDeviationAnalysisDTO::fromDomain(
                $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound,
            );
        }

        try {
            $result = $this->calculator->calculate($snapshot);
        } catch (OverflowException) {
            return $this->failures->handle(new RecordWorkoutDeviationFailureInput(
                $input->userId, $input->analysisId, $input->attemptNumber, AnalysisFailureCode::ArithmeticOverflow,
            ));
        } catch (Throwable $exception) {
            $this->failures->handle(new RecordWorkoutDeviationFailureInput(
                $input->userId, $input->analysisId, $input->attemptNumber, AnalysisFailureCode::CalculationFailed,
            ));

            throw $exception;
        }

        return $this->transaction->execute($userId, function () use ($input, $analysisId, $userId, $result): WorkoutDeviationAnalysisDTO {
            $analysis = $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound;
            if ($analysis->completeDeviationAttempt($input->attemptNumber, $result, $this->clock->now())) {
                $analysis->scheduleAI($this->clock->now());
                $this->analyses->save($analysis);
                $task = AIAnalysisTask::fromDomain($analysis);
                $this->transaction->afterCommit(fn () => $this->aiScheduler->schedule($task));
            }

            return WorkoutDeviationAnalysisDTO::fromDomain($analysis);
        });
    }
}
