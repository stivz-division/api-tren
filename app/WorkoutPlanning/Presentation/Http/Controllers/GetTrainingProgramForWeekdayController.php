<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday\GetTrainingProgramForWeekday;
use App\WorkoutPlanning\Presentation\Http\Requests\GetTrainingProgramForWeekdayRequest;
use App\WorkoutPlanning\Presentation\Http\Resources\TrainingProgramResource;

final class GetTrainingProgramForWeekdayController extends Controller
{
    public function __invoke(
        GetTrainingProgramForWeekdayRequest $request,
        GetTrainingProgramForWeekday $getTrainingProgramForWeekday,
    ): TrainingProgramResource {
        return new TrainingProgramResource(
            $getTrainingProgramForWeekday->handle($request->toInput()),
        );
    }
}
