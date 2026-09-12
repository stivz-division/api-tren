<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgram;
use App\WorkoutPlanning\Presentation\Http\Requests\StoreTrainingProgramRequest;
use App\WorkoutPlanning\Presentation\Http\Resources\TrainingProgramResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class StoreTrainingProgramController extends Controller
{
    #[OpenApiResponse(
        status: 409,
        description: 'A program already exists for the weekday or schedule mutation is in progress.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        StoreTrainingProgramRequest $request,
        CreateTrainingProgram $createTrainingProgram,
    ): JsonResponse {
        $trainingProgram = $createTrainingProgram->handle($request->toInput());

        return (new TrainingProgramResource($trainingProgram))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
