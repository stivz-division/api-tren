<?php

namespace App\WorkoutAnalysis\Application\UseCases\GetWorkoutDeviationAnalysis;

use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;

final readonly class GetWorkoutDeviationAnalysis
{
    public function __construct(private WorkoutAnalysisRepository $analyses) {}

    public function handle(GetWorkoutDeviationAnalysisInput $input): WorkoutDeviationAnalysisDTO
    {
        $analysis = $this->analyses->findForUser(new WorkoutAnalysisId($input->analysisId), new UserId($input->userId))
            ?? throw new WorkoutAnalysisNotFound;

        return WorkoutDeviationAnalysisDTO::fromDomain($analysis);
    }
}
