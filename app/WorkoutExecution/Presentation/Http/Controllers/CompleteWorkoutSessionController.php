<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession\CompleteWorkoutSession;
use App\WorkoutExecution\Presentation\Http\Requests\CompleteWorkoutSessionRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class CompleteWorkoutSessionController extends Controller
{
    #[PathParameter('workoutSessionId', type: 'int<1, max>')]
    #[OpenApiResponse(
        status: 404,
        description: 'The workout session does not exist or belongs to another user.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 409,
        description: 'The workout cannot be completed in its current state or a mutation is in progress.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        CompleteWorkoutSessionRequest $request,
        CompleteWorkoutSession $completeWorkoutSession,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $completeWorkoutSession->handle($request->toInput()),
        );
    }
}
