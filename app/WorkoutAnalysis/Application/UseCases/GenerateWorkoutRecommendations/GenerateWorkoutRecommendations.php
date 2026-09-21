<?php

namespace App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutRecommendations;

use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\RecommendationPlanGateway;
use App\WorkoutAnalysis\Application\Gateways\RecommendationProvider;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutRecommendationFailure\RecordWorkoutRecommendationFailure;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutRecommendationFailure\RecordWorkoutRecommendationFailureInput;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Entities\WorkoutRecommendationGeneration;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\Services\RecommendationAdmissionPolicy;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use LogicException;
use Throwable;

final readonly class GenerateWorkoutRecommendations
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AIExecutionPolicy $policy,
        private RecommendationProvider $provider,
        private RecommendationPlanGateway $plans,
        private RecommendationAdmissionPolicy $admission,
        private RecordWorkoutRecommendationFailure $failures,
    ) {}

    public function handle(GenerateWorkoutRecommendationsInput $input): ?WorkoutRecommendationGeneration
    {
        $userId = new UserId($input->userId);
        $analysisId = new WorkoutAnalysisId($input->analysisId);
        $claimed = $this->transaction->execute($userId, function () use ($input, $userId, $analysisId): ?WorkoutAnalysis {
            $analysis = $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound;
            $now = $this->clock->now();
            if (! $analysis->startRecommendationAttempt($input->attemptNumber, $now, $this->policy->expiresAt($now))) {
                return null;
            }
            $this->analyses->save($analysis);

            return $analysis;
        });
        if ($claimed === null) {
            return $this->analyses->findForUser($analysisId, $userId)?->recommendations();
        }
        try {
            $context = $claimed->recommendationContext() ?? throw new LogicException('Отсутствует зафиксированный контекст программы.');
            $conclusion = $claimed->ai()->result ?? throw new LogicException('Отсутствует заключение ИИ.');
            $batch = $context['exercises'] === []
                ? new RecommendationBatch([], 'Исходная программа или её упражнения больше недоступны.')
                : $this->admission->admit($this->provider->generate($conclusion, $context), $conclusion, $context);
        } catch (AIProviderFailed $exception) {
            return $this->failures->handle(new RecordWorkoutRecommendationFailureInput($input->userId, $input->analysisId, $input->attemptNumber, $exception->failureCode));
        } catch (Throwable) {
            return $this->failures->handle(new RecordWorkoutRecommendationFailureInput($input->userId, $input->analysisId, $input->attemptNumber, AnalysisFailureCode::WorkerFailed));
        }
        if ($batch->proposals === [] && $batch->rejectedReasons !== []) {
            return $this->failures->handle(new RecordWorkoutRecommendationFailureInput($input->userId, $input->analysisId, $input->attemptNumber, AnalysisFailureCode::RecommendationsRejected, $batch->rejectedReasons));
        }

        return $this->transaction->execute($userId, function () use ($input, $analysisId, $userId, $batch, $context): ?WorkoutRecommendationGeneration {
            $analysis = $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound;
            $now = $this->clock->now();
            if ($analysis->completeRecommendationAttempt($input->attemptNumber, $batch, $now)) {
                $this->analyses->save($analysis);
                $this->plans->store($analysis, $context, $batch->proposals, $now);
            }

            return $analysis->recommendations();
        });
    }
}
