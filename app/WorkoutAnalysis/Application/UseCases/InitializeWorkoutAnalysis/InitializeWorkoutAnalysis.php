<?php

namespace App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis;

use App\WorkoutAnalysis\Application\DTO\DeviationTask;
use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use App\WorkoutAnalysis\Application\Exceptions\CompletedWorkoutNotFound;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

final readonly class InitializeWorkoutAnalysis
{
    public function __construct(
        private WorkoutAnalysisRepository $analyses,
        private CompletedWorkoutProvider $workouts,
        private CompletedWorkoutSnapshotFactory $factory,
        private AnalysisClock $clock,
        private AnalysisTransaction $transaction,
        private AnalysisTaskScheduler $scheduler,
    ) {}

    public function handle(InitializeWorkoutAnalysisInput $input): WorkoutDeviationAnalysisDTO
    {
        $userId = new UserId($input->userId);
        $sessionId = new WorkoutSessionId($input->workoutSessionId);
        $existing = $this->analyses->findForSession($sessionId, $userId);
        if ($existing !== null) {
            return WorkoutDeviationAnalysisDTO::fromDomain($existing);
        }

        $data = $this->workouts->findForUser($sessionId, $userId) ?? throw new CompletedWorkoutNotFound;
        $snapshot = $this->factory->create($data, $userId, $sessionId);

        return $this->transaction->execute($userId, function () use ($userId, $sessionId, $snapshot): WorkoutDeviationAnalysisDTO {
            $existing = $this->analyses->findForSession($sessionId, $userId);
            if ($existing !== null) {
                return WorkoutDeviationAnalysisDTO::fromDomain($existing);
            }

            $analysis = $this->analyses->add(WorkoutAnalysis::initialize($snapshot, $this->clock->now()));
            $task = DeviationTask::fromDomain($analysis);
            $this->transaction->afterCommit(fn () => $this->scheduler->schedule($task));

            return WorkoutDeviationAnalysisDTO::fromDomain($analysis);
        });
    }
}
