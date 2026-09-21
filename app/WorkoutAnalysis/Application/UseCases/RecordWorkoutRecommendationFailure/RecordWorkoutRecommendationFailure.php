<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecordWorkoutRecommendationFailure;

use App\WorkoutAnalysis\Application\DTO\RecommendationTask;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\RecommendationTaskScheduler;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Domain\Entities\WorkoutRecommendationGeneration;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class RecordWorkoutRecommendationFailure
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private RecommendationTaskScheduler $scheduler,
        private AIExecutionPolicy $policy,
    ) {}

    /**
     * Вызывается обработчиком, успешно захватившим указанную попытку.
     * Ошибки доставки до захвата и общий Job::failed() без подтверждения владения
     * не вызывают эту операцию: потерянное выполнение восстанавливается по сроку.
     */
    public function handle(RecordWorkoutRecommendationFailureInput $input): ?WorkoutRecommendationGeneration
    {
        $userId = new UserId($input->userId);

        return $this->transaction->execute($userId, function () use ($input, $userId): ?WorkoutRecommendationGeneration {
            $analysis = $this->analyses->findForUser(new WorkoutAnalysisId($input->analysisId), $userId)
                ?? throw new WorkoutAnalysisNotFound;
            if ($analysis->recommendations() === null) {
                return null;
            }
            $now = $this->clock->now();
            $retryAt = $this->policy->retryAt($analysis->recommendations()->currentAttempt(), $input->failureCode, $now);

            if ($analysis->failRecommendationAttempt($input->attemptNumber, $input->failureCode, $now, $retryAt, $input->rejectedReasons)) {
                $this->analyses->save($analysis);
                if ($analysis->recommendations()?->status() === AnalysisStatus::Pending) {
                    $task = RecommendationTask::fromDomain($analysis);
                    $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
                }
            }

            return $analysis->recommendations();
        });
    }
}
