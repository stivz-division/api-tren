<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgramInput;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class DeleteTrainingProgramController extends Controller
{
    #[PathParameter('trainingProgramId', type: 'int<1, max>')]
    #[OpenApiResponse(
        status: 404,
        description: 'The training program does not exist or belongs to another user.',
        type: 'array{code: string, message: string}',
    )]
    #[OpenApiResponse(
        status: 409,
        description: 'Another schedule mutation is in progress.',
        type: 'array{code: string, message: string}',
    )]
    public function __invoke(
        Request $request,
        int $trainingProgramId,
        DeleteTrainingProgram $deleteTrainingProgram,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Авторизованный пользователь недоступен.');
        }

        $deleteTrainingProgram->handle(new DeleteTrainingProgramInput(
            userId: $user->id,
            trainingProgramId: $trainingProgramId,
        ));

        return response()->noContent();
    }
}
