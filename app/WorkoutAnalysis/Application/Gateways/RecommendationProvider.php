<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;

/** @phpstan-type RecommendationProgramContext array{program_id:int, exercises:list<array{exercise_id:int, revision?:int, sets:list<array{position:int,repetitions:int,working_weight_grams:int}>, successes:int, failures:int, completed_since_replacement:int, completed_since_rejection:int, currently_successful:bool}>, catalog:list<array{id:int,name:string}>} */
interface RecommendationProvider
{
    /** @param RecommendationProgramContext $programContext */
    public function generate(WorkoutAIResult $analysis, array $programContext): RecommendationBatch;
}
