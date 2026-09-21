<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecordWorkoutAIFailure;

use App\WorkoutAnalysis\Application\DTO\AIAnalysisTask;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AIAnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAIAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class RecordWorkoutAIFailure
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AIAnalysisTaskScheduler $scheduler,
        private AIExecutionPolicy $policy,
    ) {}

    /**
     * Вызывается обработчиком, успешно захватившим указанную попытку.
     * Ошибки доставки до захвата и общий Job::failed() без подтверждения владения
     * не вызывают эту операцию: потерянное выполнение восстанавливается по сроку.
     */
    public function handle(RecordWorkoutAIFailureInput $input): ?WorkoutAIAnalysis
    {
        $userId = new UserId($input->userId);

        return $this->transaction->execute($userId, function () use ($input, $userId): ?WorkoutAIAnalysis {
            $analysis = $this->analyses->findForUser(new WorkoutAnalysisId($input->analysisId), $userId)
                ?? throw new WorkoutAnalysisNotFound;
            if ($analysis->ai() === null) {
                return null;
            }
            $now = $this->clock->now();
            $retryAt = $this->policy->retryAt($analysis->ai()->currentAttempt(), $input->failureCode, $now);

            if ($analysis->failAIAttempt($input->attemptNumber, $input->failureCode, $now, $retryAt)) {
                $this->analyses->save($analysis);
                if ($analysis->ai()?->status() === AnalysisStatus::Pending) {
                    $task = AIAnalysisTask::fromDomain($analysis);
                    $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
                }
            }

            return $analysis->ai();
        });
    }
}
