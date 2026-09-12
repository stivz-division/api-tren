<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\SkipExercise\SkipExercise;
use App\WorkoutExecution\Presentation\Http\Requests\SkipExerciseRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;

final class SkipExerciseController extends Controller
{
    public function __invoke(
        SkipExerciseRequest $request,
        SkipExercise $skipExercise,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $skipExercise->handle($request->toInput()),
        );
    }
}
