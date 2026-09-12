<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSession;
use App\WorkoutExecution\Presentation\Http\Requests\StartWorkoutSessionRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;

final class StartWorkoutSessionController extends Controller
{
    public function __invoke(
        StartWorkoutSessionRequest $request,
        StartWorkoutSession $startWorkoutSession,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $startWorkoutSession->handle($request->toInput()),
        );
    }
}
