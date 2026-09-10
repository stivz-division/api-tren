<?php

use App\WorkoutPlanning\Application\Exceptions\ExerciseNotFound;
use App\WorkoutPlanning\Application\Exceptions\TrainingProgramMutationInProgress;
use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Domain\Exceptions\ExerciseAlreadyPlanned;
use App\WorkoutPlanning\Domain\Exceptions\InvalidWeekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            fn (TrainingProgramNotFound $exception): JsonResponse => response()->json([
                'code' => 'training_program_not_found',
                'message' => 'Программа тренировок не найдена.',
            ], Response::HTTP_NOT_FOUND),
        );
        $exceptions->render(
            fn (TrainingProgramAlreadyExists $exception): JsonResponse => response()->json([
                'code' => 'training_program_already_exists',
                'message' => 'На этот день уже создана программа тренировок.',
            ], Response::HTTP_CONFLICT),
        );
        $exceptions->render(
            fn (TrainingProgramMutationInProgress $exception): JsonResponse => response()->json([
                'code' => 'training_program_mutation_in_progress',
                'message' => 'Изменение расписания уже выполняется. Повторите попытку.',
            ], Response::HTTP_CONFLICT),
        );
        $exceptions->render(
            fn (ExerciseNotFound $exception): JsonResponse => response()->json([
                'code' => 'exercise_not_found',
                'message' => 'Одно или несколько упражнений не найдены.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY),
        );
        $exceptions->render(
            fn (ExerciseAlreadyPlanned $exception): JsonResponse => response()->json([
                'code' => 'exercise_already_planned',
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY),
        );
        $exceptions->render(
            fn (TrainingProgramMustContainExercise $exception): JsonResponse => response()->json([
                'code' => 'training_program_must_contain_exercise',
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY),
        );
        $exceptions->render(
            fn (InvalidWeekday $exception): JsonResponse => response()->json([
                'code' => 'invalid_weekday',
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    })->create();
