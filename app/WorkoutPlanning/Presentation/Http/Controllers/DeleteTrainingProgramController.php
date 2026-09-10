<?php

namespace App\WorkoutPlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgramInput;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final class DeleteTrainingProgramController extends Controller
{
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
