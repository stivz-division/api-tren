<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\GetWorkoutSessionHistory\GetWorkoutSessionHistory;
use App\WorkoutExecution\Presentation\Http\Requests\GetWorkoutSessionHistoryRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionHistoryCollection;

final class GetWorkoutSessionHistoryController extends Controller
{
    public function __invoke(
        GetWorkoutSessionHistoryRequest $request,
        GetWorkoutSessionHistory $getWorkoutSessionHistory,
    ): WorkoutSessionHistoryCollection {
        return new WorkoutSessionHistoryCollection(
            $getWorkoutSessionHistory->handle($request->toInput()),
        );
    }
}
