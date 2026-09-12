<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\CancelWorkoutSession\CancelWorkoutSession;
use App\WorkoutExecution\Presentation\Http\Requests\CancelWorkoutSessionRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;

final class CancelWorkoutSessionController extends Controller
{
    public function __invoke(
        CancelWorkoutSessionRequest $request,
        CancelWorkoutSession $cancelWorkoutSession,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $cancelWorkoutSession->handle($request->toInput()),
        );
    }
}
