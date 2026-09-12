<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutPlanning\Application\UseCases\GetTrainingPrograms\GetTrainingPrograms;
use App\WorkoutPlanning\Presentation\Http\Requests\GetTrainingProgramsRequest;
use App\WorkoutPlanning\Presentation\Http\Resources\TrainingProgramResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class GetTrainingProgramsController extends Controller
{
    public function __invoke(
        GetTrainingProgramsRequest $request,
        GetTrainingPrograms $getTrainingPrograms,
    ): AnonymousResourceCollection {
        return TrainingProgramResource::collection(
            $getTrainingPrograms->handle($request->toInput()),
        );
    }
}
