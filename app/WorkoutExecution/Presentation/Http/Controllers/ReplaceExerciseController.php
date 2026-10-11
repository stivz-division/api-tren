<?php

namespace App\WorkoutExecution\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExercise;
use App\WorkoutExecution\Presentation\Http\Requests\ReplaceExerciseRequest;
use App\WorkoutExecution\Presentation\Http\Resources\WorkoutSessionResource;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

final class ReplaceExerciseController extends Controller
{
    #[PathParameter('workoutSessionId', type: 'int<1, max>')]
    #[PathParameter(
        'exerciseId',
        description: 'Original catalog exercise ID of the session position. Remains the command ID after every replacement.',
        type: 'int<1, max>',
    )]
    #[OpenApiResponse(
        status: 404,
        description: 'The workout session or exercise does not exist for this user.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 409,
        description: 'The session or exercise state, duplicate exercise, changed plan, conflicting request UUID, or mutation lock prevents replacement.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 422,
        description: 'The replacement payload is invalid or the replacement exercise is absent from the catalog.',
        type: 'array{code?: string, message: string, errors?: array<string, list<string>>}',
    )]
    /**
     * Replace an exercise in an active workout.
     *
     * New replacements require an in_progress session and pending exercise. The supplied sets replace the draft.
     * replace_in_plan defaults to false; true also updates the program and includes the new load in current analysis.
     * Reusing a UUID with the same normalized payload returns the full current session without resetting progress,
     * including after completion or catalog deletion. A different payload for that UUID returns 409.
     */
    public function __invoke(
        ReplaceExerciseRequest $request,
        ReplaceExercise $replaceExercise,
    ): WorkoutSessionResource {
        return new WorkoutSessionResource(
            $replaceExercise->handle($request->toInput()),
        );
    }
}
