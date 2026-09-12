<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\ReopenExercise\ReopenExercise;
use App\WorkoutExecution\Presentation\Http\Requests\ReopenExerciseRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class ReopenExerciseController extends Controller
{
    #[PathParameter('workoutSessionId', type: 'int<1, max>')]
    #[PathParameter('exerciseId', type: 'int<1, max>')]
    #[OpenApiResponse(
        status: 404,
        description: 'The workout session or exercise does not exist for this user.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 409,
        description: 'The exercise cannot be reopened in its current state or a mutation is in progress.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        ReopenExerciseRequest $request,
        ReopenExercise $reopenExercise,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $reopenExercise->handle($request->toInput()),
        );
    }
}
