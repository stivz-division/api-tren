<?php

namespace App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis;

use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AIProvider;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContext;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContextInput;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutAIFailure\RecordWorkoutAIFailure;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutAIFailure\RecordWorkoutAIFailureInput;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAIAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use Throwable;

final readonly class GenerateWorkoutAIAnalysis
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AIExecutionPolicy $policy,
        private PrepareWorkoutAnalysisContext $prepare,
        private AIProvider $provider,
        private RecordWorkoutAIFailure $failures,
    ) {}

    /** Worker вызывает сценарий без внешней транзакции: сеть работает после commit захвата и контекста. */
    public function handle(GenerateWorkoutAIAnalysisInput $input): ?WorkoutAIAnalysis
    {
        $userId = new UserId($input->userId);
        $analysisId = new WorkoutAnalysisId($input->analysisId);
        $claimed = $this->transaction->execute($userId, function () use ($input, $userId, $analysisId): bool {
            $analysis = $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound;
            $now = $this->clock->now();
            if (! $analysis->startAIAttempt($input->attemptNumber, $now, $this->policy->expiresAt($now))) {
                return false;
            }
            $this->analyses->save($analysis);

            return true;
        });
        if (! $claimed) {
            return $this->analyses->findForUser($analysisId, $userId)?->ai();
        }
        try {
            $context = $this->prepare->handle(new PrepareWorkoutAnalysisContextInput($input->userId, $input->analysisId));
        } catch (WorkoutAnalysisNotFound $exception) {
            throw $exception;
        } catch (Throwable) {
            return $this->failures->handle(new RecordWorkoutAIFailureInput($input->userId, $input->analysisId, $input->attemptNumber, AnalysisFailureCode::ContextPreparationFailed));
        }
        try {
            $result = $this->provider->analyze($analysisId, $context);
        } catch (AIProviderFailed $exception) {
            return $this->failures->handle(new RecordWorkoutAIFailureInput($input->userId, $input->analysisId, $input->attemptNumber, $exception->failureCode));
        } catch (Throwable) {
            return $this->failures->handle(new RecordWorkoutAIFailureInput($input->userId, $input->analysisId, $input->attemptNumber, AnalysisFailureCode::WorkerFailed));
        }

        return $this->transaction->execute($userId, function () use ($input, $analysisId, $userId, $result): ?WorkoutAIAnalysis {
            $analysis = $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound;
            if ($analysis->completeAIAttempt($input->attemptNumber, $result, $this->clock->now())) {
                $this->analyses->save($analysis);
            }

            return $analysis->ai();
        });
    }
}
