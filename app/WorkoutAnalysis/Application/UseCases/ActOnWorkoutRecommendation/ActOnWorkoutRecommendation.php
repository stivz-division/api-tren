<?php

namespace App\WorkoutAnalysis\Application\UseCases\ActOnWorkoutRecommendation;

use App\WorkoutAnalysis\Application\DTO\WorkoutRecommendationDTO;
use App\WorkoutAnalysis\Application\Exceptions\RecommendationConflict;
use App\WorkoutAnalysis\Application\Gateways\RecommendationPlanGateway;
use InvalidArgumentException;

final readonly class ActOnWorkoutRecommendation
{
    public function __construct(private RecommendationPlanGateway $plans) {}

    public function handle(ActOnWorkoutRecommendationInput $input, string $action): WorkoutRecommendationDTO
    {
        if (! in_array($action, ['apply', 'reject'], true)) {
            throw new InvalidArgumentException('Неизвестное действие с рекомендацией.');
        }
        $result = $this->plans->act($input->userId, $input->recommendationId, $action);
        if ($result->status === 'expired') {
            throw new RecommendationConflict;
        }

        return WorkoutRecommendationDTO::fromDomain($result);
    }
}
