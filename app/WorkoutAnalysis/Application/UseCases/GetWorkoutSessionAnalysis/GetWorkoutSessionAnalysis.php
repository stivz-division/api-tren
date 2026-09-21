<?php

namespace App\WorkoutAnalysis\Application\UseCases\GetWorkoutSessionAnalysis;

use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\RecommendationPlanGateway;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

final readonly class GetWorkoutSessionAnalysis
{
    public function __construct(private WorkoutAnalysisRepository $analyses, private RecommendationPlanGateway $plans) {}

    public function handle(GetWorkoutSessionAnalysisInput $input): WorkoutDeviationAnalysisDTO
    {
        $analysis = $this->analyses->findForSession(
            new WorkoutSessionId($input->workoutSessionId),
            new UserId($input->userId),
        ) ?? throw new WorkoutAnalysisNotFound;

        return WorkoutDeviationAnalysisDTO::fromDomain($analysis, $analysis->id === null ? [] : $this->plans->recommendations($input->userId, $analysis->id->value));
    }
}
