<?php

use App\WorkoutExecution\Presentation\Http\Controllers\CancelWorkoutSessionController;
use App\WorkoutExecution\Presentation\Http\Controllers\CompleteExerciseController;
use App\WorkoutExecution\Presentation\Http\Controllers\CompleteWorkoutSessionController;
use App\WorkoutExecution\Presentation\Http\Controllers\GetActiveWorkoutSessionController;
use App\WorkoutExecution\Presentation\Http\Controllers\ReopenExerciseController;
use App\WorkoutExecution\Presentation\Http\Controllers\SaveExerciseProgressController;
use App\WorkoutExecution\Presentation\Http\Controllers\SkipExerciseController;
use App\WorkoutExecution\Presentation\Http\Controllers\StartWorkoutSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('workout-sessions/active', GetActiveWorkoutSessionController::class)
        ->name('api.workout-sessions.active.show');
    Route::put('workout-sessions/active', StartWorkoutSessionController::class)
        ->name('api.workout-sessions.active.update');
    Route::put(
        'workout-sessions/{workoutSessionId}/exercises/{exerciseId}/sets',
        SaveExerciseProgressController::class,
    )
        ->whereNumber(['workoutSessionId', 'exerciseId'])
        ->name('api.workout-sessions.exercises.sets.update');
    Route::post(
        'workout-sessions/{workoutSessionId}/exercises/{exerciseId}/complete',
        CompleteExerciseController::class,
    )
        ->whereNumber(['workoutSessionId', 'exerciseId'])
        ->name('api.workout-sessions.exercises.complete');
    Route::post(
        'workout-sessions/{workoutSessionId}/exercises/{exerciseId}/skip',
        SkipExerciseController::class,
    )
        ->whereNumber(['workoutSessionId', 'exerciseId'])
        ->name('api.workout-sessions.exercises.skip');
    Route::post(
        'workout-sessions/{workoutSessionId}/exercises/{exerciseId}/reopen',
        ReopenExerciseController::class,
    )
        ->whereNumber(['workoutSessionId', 'exerciseId'])
        ->name('api.workout-sessions.exercises.reopen');
    Route::post(
        'workout-sessions/{workoutSessionId}/complete',
        CompleteWorkoutSessionController::class,
    )
        ->whereNumber('workoutSessionId')
        ->name('api.workout-sessions.complete');
    Route::post(
        'workout-sessions/{workoutSessionId}/cancel',
        CancelWorkoutSessionController::class,
    )
        ->whereNumber('workoutSessionId')
        ->name('api.workout-sessions.cancel');
});
