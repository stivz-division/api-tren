<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\GetExerciseController;
use App\Http\Middleware\ValidateTelegramWebAppData;
use Illuminate\Support\Facades\Route;

Route::post('auth', AuthController::class)
    ->middleware([
        'throttle:telegram-auth',
        ValidateTelegramWebAppData::class,
    ])
    ->name('api.auth');

Route::get('exercises', ExerciseController::class)
    ->middleware('auth:sanctum')
    ->name('api.exercises.index');

Route::get('exercises/{exercise}', GetExerciseController::class)
    ->whereNumber('exercise')
    ->middleware('auth:sanctum')
    ->name('api.exercises.show');

require base_path('app/WorkoutPlanning/Presentation/Routes/api.php');
require base_path('app/WorkoutExecution/Presentation/Routes/api.php');
require base_path('app/WorkoutAnalysis/Presentation/Routes/api.php');
