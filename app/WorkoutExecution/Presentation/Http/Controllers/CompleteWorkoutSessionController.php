<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession\CompleteWorkoutSession;
use App\WorkoutExecution\Presentation\Http\Requests\CompleteWorkoutSessionRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;

final class CompleteWorkoutSessionController extends Controller
{
    public function __invoke(
        CompleteWorkoutSessionRequest $request,
        CompleteWorkoutSession $completeWorkoutSession,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $completeWorkoutSession->handle($request->toInput()),
        );
    }
}
