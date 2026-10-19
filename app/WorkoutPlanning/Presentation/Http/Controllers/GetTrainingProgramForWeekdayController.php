<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday\GetTrainingProgramForWeekday;
use App\WorkoutPlanning\Presentation\Http\Requests\GetTrainingProgramForWeekdayRequest;
use App\WorkoutPlanning\Presentation\Http\Resources\TrainingProgramResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class GetTrainingProgramForWeekdayController extends Controller
{
    #[PathParameter('weekday', type: 'int<1, 7>')]
    public function __invoke(
        GetTrainingProgramForWeekdayRequest $request,
        GetTrainingProgramForWeekday $getTrainingProgramForWeekday,
    ): AnonymousResourceCollection {
        return TrainingProgramResource::collection(
            $getTrainingProgramForWeekday->handle($request->toInput()),
        );
    }
}
