<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\GetActiveWorkoutSession\GetActiveWorkoutSession;
use App\WorkoutExecution\Presentation\Http\Requests\GetActiveWorkoutSessionRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;
use Illuminate\Http\JsonResponse;

final class GetActiveWorkoutSessionController extends Controller
{
    public function __invoke(
        GetActiveWorkoutSessionRequest $request,
        GetActiveWorkoutSession $getActiveWorkoutSession,
    ): JsonResponse|WorkoutSessionResource {
        $session = $getActiveWorkoutSession->handle($request->toInput());

        return $session === null
            ? response()->json(['data' => null])
            : new WorkoutSessionResource($session);
    }
}
