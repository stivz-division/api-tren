<?php

namespace App\WorkoutAnalysis\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutAnalysis\Application\UseCases\ActOnWorkoutRecommendation\ActOnWorkoutRecommendation;
use App\WorkoutAnalysis\Presentation\Http\Requests\ActOnWorkoutRecommendationRequest;
use App\WorkoutAnalysis\Presentation\Http\Resources\WorkoutRecommendationResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class RejectWorkoutRecommendationController extends Controller
{
    /** Отказаться от рекомендации. */
    #[PathParameter('recommendationId', type: 'int<1, max>')]
    #[OpenApiResponse(status: 404, description: 'Рекомендация отсутствует или принадлежит другому пользователю.', type: 'array{code: string, message: string}')]
    #[OpenApiResponse(status: 409, description: 'Рекомендация устарела, уже обработана другим действием или программа изменяется.', type: 'array{code: string, message: string}')]
    public function __invoke(ActOnWorkoutRecommendationRequest $request, ActOnWorkoutRecommendation $act): WorkoutRecommendationResource
    {
        return new WorkoutRecommendationResource($act->handle($request->toInput(), 'reject'));
    }
}
