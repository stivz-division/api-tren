<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgram;
use App\WorkoutPlanning\Presentation\Http\Requests\UpdateTrainingProgramRequest;
use App\WorkoutPlanning\Presentation\Http\Resources\TrainingProgramResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class UpdateTrainingProgramController extends Controller
{
    #[PathParameter('trainingProgramId', type: 'int<1, max>')]
    #[OpenApiResponse(
        status: 404,
        description: 'The training program does not exist or belongs to another user.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 409,
        description: 'Another schedule mutation is in progress.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        UpdateTrainingProgramRequest $request,
        UpdateTrainingProgram $updateTrainingProgram,
    ): TrainingProgramResource {
        return new TrainingProgramResource(
            $updateTrainingProgram->handle($request->toInput()),
        );
    }
}
