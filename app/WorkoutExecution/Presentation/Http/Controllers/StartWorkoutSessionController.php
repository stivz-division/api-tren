<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSession;
use App\WorkoutExecution\Presentation\Http\Requests\StartWorkoutSessionRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class StartWorkoutSessionController extends Controller
{
    #[OpenApiResponse(
        status: 404,
        description: 'The training program does not exist or belongs to another user.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 409,
        description: 'A workout is already active, its snapshot is invalid, or a mutation is in progress.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        StartWorkoutSessionRequest $request,
        StartWorkoutSession $startWorkoutSession,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $startWorkoutSession->handle($request->toInput()),
        );
    }
}
