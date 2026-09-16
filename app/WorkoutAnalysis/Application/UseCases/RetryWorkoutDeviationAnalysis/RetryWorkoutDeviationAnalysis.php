<?php

namespace App\WorkoutAnalysis\Application\UseCases\RetryWorkoutDeviationAnalysis;

use App\WorkoutAnalysis\Application\DTO\DeviationTask;
use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class RetryWorkoutDeviationAnalysis
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AnalysisTaskScheduler $scheduler,
    ) {}

    /** Служебная операция. Публичный пользовательский endpoint не предоставляется. */
    public function handle(RetryWorkoutDeviationAnalysisInput $input): WorkoutDeviationAnalysisDTO
    {
        $userId = new UserId($input->userId);

        return $this->transaction->execute($userId, function () use ($input, $userId): WorkoutDeviationAnalysisDTO {
            $analysis = $this->analyses->findForUser(new WorkoutAnalysisId($input->analysisId), $userId)
                ?? throw new WorkoutAnalysisNotFound;

            if ($analysis->retryDeviations($this->clock->now())) {
                $this->analyses->save($analysis);
                $task = DeviationTask::fromDomain($analysis);
                $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
            }

            return WorkoutDeviationAnalysisDTO::fromDomain($analysis);
        });
    }
}
