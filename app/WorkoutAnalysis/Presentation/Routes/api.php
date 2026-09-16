<?php

use App\WorkoutAnalysis\Presentation\Http\Controllers\GetWorkoutSessionAnalysisController;
use Illuminate\Support\Facades\Route;

Route::get('workout-sessions/{workoutSessionId}/analysis', GetWorkoutSessionAnalysisController::class)
    ->middleware('auth:sanctum')
    ->whereNumber('workoutSessionId')
    ->name('api.workout-sessions.analysis.show');
