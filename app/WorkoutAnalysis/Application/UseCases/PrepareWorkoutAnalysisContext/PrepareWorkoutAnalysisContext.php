<?php

namespace App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext;

use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryQuery;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutDeviationsNotReady;
use App\WorkoutAnalysis\Application\Factories\AnalysisContextSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\WorkoutHistoryProvider;
use App\WorkoutAnalysis\Application\Policies\AnalysisHistoryPolicy;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class PrepareWorkoutAnalysisContext
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private WorkoutHistoryProvider $history,
        private AnalysisContextSnapshotFactory $factory,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AnalysisHistoryPolicy $policy,
    ) {}

    /** Внутренний шаг подготовки перед ИИ: возвращает зафиксированный доменный снимок. */
    public function handle(PrepareWorkoutAnalysisContextInput $input): AnalysisContextSnapshot
    {
        $userId = new UserId($input->userId);
        $analysisId = new WorkoutAnalysisId($input->analysisId);

        return $this->transaction->execute($userId, function () use ($userId, $analysisId): AnalysisContextSnapshot {
            $analysis = $this->analyses->findForUser($analysisId, $userId) ?? throw new WorkoutAnalysisNotFound;
            $existing = $analysis->context();
            if ($existing !== null) {
                return $existing;
            }

            $deviations = $analysis->deviations();
            if ($deviations->status() !== AnalysisStatus::Completed || $deviations->result === null) {
                throw new WorkoutDeviationsNotReady;
            }

            $snapshot = $deviations->snapshot;
            $history = $this->history->read(new WorkoutHistoryQuery(
                $userId->value,
                $snapshot->workoutSessionId->value,
                $snapshot->trainingProgramId->value,
                $snapshot->completedAt,
                $this->policy->sameProgramLimit,
                $this->policy->otherProgramsLimit,
            ));
            $context = $this->factory->create($deviations->result, $history, $this->policy, $this->clock->now());
            $analysis->attachContext($context);
            $this->analyses->save($analysis);

            return $context;
        });
    }
}
