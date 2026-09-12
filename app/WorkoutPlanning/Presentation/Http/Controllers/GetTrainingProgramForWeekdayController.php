<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday\GetTrainingProgramForWeekday;
use App\WorkoutPlanning\Presentation\Http\Requests\GetTrainingProgramForWeekdayRequest;
use App\WorkoutPlanning\Presentation\Http\Resources\TrainingProgramResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class GetTrainingProgramForWeekdayController extends Controller
{
    #[PathParameter('weekday', type: 'int<1, 7>')]
    #[OpenApiResponse(
        status: 404,
        description: 'No training program exists for the requested weekday.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        GetTrainingProgramForWeekdayRequest $request,
        GetTrainingProgramForWeekday $getTrainingProgramForWeekday,
    ): TrainingProgramResource {
        return new TrainingProgramResource(
            $getTrainingProgramForWeekday->handle($request->toInput()),
        );
    }
}
