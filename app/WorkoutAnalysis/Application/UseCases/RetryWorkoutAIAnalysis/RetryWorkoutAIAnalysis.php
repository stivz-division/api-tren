<?php

namespace App\WorkoutAnalysis\Application\UseCases\RetryWorkoutAIAnalysis;

use App\WorkoutAnalysis\Application\DTO\AIAnalysisTask;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AIAnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAIAnalysis;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class RetryWorkoutAIAnalysis
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AIAnalysisTaskScheduler $scheduler,
    ) {}

    /** Служебная операция. Публичный пользовательский endpoint не предоставляется. */
    public function handle(RetryWorkoutAIAnalysisInput $input): ?WorkoutAIAnalysis
    {
        $userId = new UserId($input->userId);

        return $this->transaction->execute($userId, function () use ($input, $userId): ?WorkoutAIAnalysis {
            $analysis = $this->analyses->findForUser(new WorkoutAnalysisId($input->analysisId), $userId)
                ?? throw new WorkoutAnalysisNotFound;

            if ($analysis->retryAI($this->clock->now())) {
                $this->analyses->save($analysis);
                $task = AIAnalysisTask::fromDomain($analysis);
                $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
            }

            return $analysis->ai();
        });
    }
}
