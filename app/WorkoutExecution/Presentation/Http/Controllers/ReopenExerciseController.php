<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\ReopenExercise\ReopenExercise;
use App\WorkoutExecution\Presentation\Http\Requests\ReopenExerciseRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;

final class ReopenExerciseController extends Controller
{
    public function __invoke(
        ReopenExerciseRequest $request,
        ReopenExercise $reopenExercise,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $reopenExercise->handle($request->toInput()),
        );
    }
}
