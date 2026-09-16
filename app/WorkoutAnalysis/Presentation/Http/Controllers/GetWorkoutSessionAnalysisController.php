<?php

namespace App\WorkoutAnalysis\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutAnalysis\Application\UseCases\GetWorkoutSessionAnalysis\GetWorkoutSessionAnalysis;
use App\WorkoutAnalysis\Presentation\Http\Requests\GetWorkoutSessionAnalysisRequest;
use App\WorkoutAnalysis\Presentation\Http\Resources\WorkoutAnalysisResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class GetWorkoutSessionAnalysisController extends Controller
{
    /** Получить статус и сохранённое сравнение плана и факта своей тренировки. */
    #[PathParameter('workoutSessionId', type: 'int<1, max>')]
    #[OpenApiResponse(
        status: 404,
        description: 'Анализ отсутствует или принадлежит другому пользователю.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        GetWorkoutSessionAnalysisRequest $request,
        GetWorkoutSessionAnalysis $getAnalysis,
    ): WorkoutAnalysisResource {
        return new WorkoutAnalysisResource($getAnalysis->handle($request->toInput()));
    }
}
