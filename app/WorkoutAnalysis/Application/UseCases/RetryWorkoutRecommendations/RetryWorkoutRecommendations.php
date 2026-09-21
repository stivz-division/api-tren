<?php

namespace App\WorkoutAnalysis\Application\UseCases\RetryWorkoutRecommendations;

use App\WorkoutAnalysis\Application\DTO\RecommendationTask;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\RecommendationTaskScheduler;
use App\WorkoutAnalysis\Domain\Entities\WorkoutRecommendationGeneration;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class RetryWorkoutRecommendations
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private RecommendationTaskScheduler $scheduler,
    ) {}

    /** Служебная операция. Публичный пользовательский endpoint не предоставляется. */
    public function handle(RetryWorkoutRecommendationsInput $input): ?WorkoutRecommendationGeneration
    {
        $userId = new UserId($input->userId);

        return $this->transaction->execute($userId, function () use ($input, $userId): ?WorkoutRecommendationGeneration {
            $analysis = $this->analyses->findForUser(new WorkoutAnalysisId($input->analysisId), $userId)
                ?? throw new WorkoutAnalysisNotFound;

            if ($analysis->retryRecommendations($this->clock->now())) {
                $this->analyses->save($analysis);
                $task = RecommendationTask::fromDomain($analysis);
                $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
            }

            return $analysis->recommendations();
        });
    }
}
