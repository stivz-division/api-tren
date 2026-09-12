<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\SaveExerciseProgress\SaveExerciseProgress;
use App\WorkoutExecution\Presentation\Http\Requests\SaveExerciseProgressRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;

final class SaveExerciseProgressController extends Controller
{
    public function __invoke(
        SaveExerciseProgressRequest $request,
        SaveExerciseProgress $saveExerciseProgress,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $saveExerciseProgress->handle($request->toInput()),
        );
    }
}
