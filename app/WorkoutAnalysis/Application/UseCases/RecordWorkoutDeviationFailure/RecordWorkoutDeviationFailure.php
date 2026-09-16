<?php

namespace App\WorkoutAnalysis\Application\UseCases\RecordWorkoutDeviationFailure;

use App\WorkoutAnalysis\Application\DTO\DeviationTask;
use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Policies\AnalysisExecutionPolicy;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class RecordWorkoutDeviationFailure
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AnalysisTaskScheduler $scheduler,
        private AnalysisExecutionPolicy $policy,
    ) {}

    /**
     * Вызывается обработчиком, успешно захватившим указанную попытку.
     * Ошибки доставки до захвата и общий Job::failed() без подтверждения владения
     * не вызывают эту операцию: потерянное выполнение восстанавливается по сроку.
     */
    public function handle(RecordWorkoutDeviationFailureInput $input): WorkoutDeviationAnalysisDTO
    {
        $userId = new UserId($input->userId);

        return $this->transaction->execute($userId, function () use ($input, $userId): WorkoutDeviationAnalysisDTO {
            $analysis = $this->analyses->findForUser(new WorkoutAnalysisId($input->analysisId), $userId)
                ?? throw new WorkoutAnalysisNotFound;
            $now = $this->clock->now();
            $retryAt = $this->policy->retryAt($analysis->deviations()->currentAttempt(), $input->failureCode, $now);

            if ($analysis->failDeviationAttempt($input->attemptNumber, $input->failureCode, $now, $retryAt)) {
                $this->analyses->save($analysis);
                if ($analysis->deviations()->status() === AnalysisStatus::Pending) {
                    $task = DeviationTask::fromDomain($analysis);
                    $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));
                }
            }

            return WorkoutDeviationAnalysisDTO::fromDomain($analysis);
        });
    }
}
