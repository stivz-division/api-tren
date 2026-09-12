<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\CompleteExercise\CompleteExercise;
use App\WorkoutExecution\Presentation\Http\Requests\CompleteExerciseRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;

final class CompleteExerciseController extends Controller
{
    public function __invoke(
        CompleteExerciseRequest $request,
        CompleteExercise $completeExercise,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $completeExercise->handle($request->toInput()),
        );
    }
}
