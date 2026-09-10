<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgram;
use App\WorkoutPlanning\Presentation\Http\Requests\UpdateTrainingProgramRequest;
use App\WorkoutPlanning\Presentation\Http\Resources\TrainingProgramResource;

final class UpdateTrainingProgramController extends Controller
{
    public function __invoke(
        UpdateTrainingProgramRequest $request,
        UpdateTrainingProgram $updateTrainingProgram,
    ): TrainingProgramResource {
        return new TrainingProgramResource(
            $updateTrainingProgram->handle($request->toInput()),
        );
    }
}
