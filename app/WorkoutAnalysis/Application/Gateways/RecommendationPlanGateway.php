<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationProposal;
use DateTimeImmutable;

/**
 * @phpstan-type PlanSet array{position:int,repetitions:int,working_weight_grams:int}
 * @phpstan-type ProgramContext array{program_id:int,exercises:list<array{exercise_id:int,sets:list<PlanSet>,revision:int,successes:int,failures:int,completed_since_replacement:int,completed_since_rejection:int,currently_successful:bool}>,catalog:list<array{id:int,name:string}>}
 */
interface RecommendationPlanGateway
{
    /** @return ProgramContext */
    public function capture(WorkoutAnalysis $analysis): array;

    /** @param ProgramContext $programContext
     * @param  list<RecommendationProposal>  $proposals
     */
    public function store(WorkoutAnalysis $analysis, array $programContext, array $proposals, DateTimeImmutable $now): void;

    /** @return list<HistoricalRecommendation> */
    public function recommendations(int $userId, int $analysisId): array;

    public function act(int $userId, int $recommendationId, string $action): HistoricalRecommendation;
}
