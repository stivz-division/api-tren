<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\CancelWorkoutSession\CancelWorkoutSession;
use App\WorkoutExecution\Presentation\Http\Requests\CancelWorkoutSessionRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class CancelWorkoutSessionController extends Controller
{
    #[PathParameter('workoutSessionId', type: 'int<1, max>')]
    #[OpenApiResponse(
        status: 404,
        description: 'The workout session does not exist or belongs to another user.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 409,
        description: 'The workout cannot be cancelled in its current state or a mutation is in progress.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        CancelWorkoutSessionRequest $request,
        CancelWorkoutSession $cancelWorkoutSession,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $cancelWorkoutSession->handle($request->toInput()),
        );
    }
}
