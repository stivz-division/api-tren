<?php

use App\WorkoutAnalysis\Presentation\Http\Controllers\ApplyWorkoutRecommendationController;
use App\WorkoutAnalysis\Presentation\Http\Controllers\GetWorkoutSessionAnalysisController;
use App\WorkoutAnalysis\Presentation\Http\Controllers\RejectWorkoutRecommendationController;
use Illuminate\Support\Facades\Route;

Route::get('workout-sessions/{workoutSessionId}/analysis', GetWorkoutSessionAnalysisController::class)
    ->middleware('auth:sanctum')
    ->whereNumber('workoutSessionId')
    ->name('api.workout-sessions.analysis.show');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('workout-recommendations/{recommendationId}/apply', ApplyWorkoutRecommendationController::class)
        ->whereNumber('recommendationId')->name('api.workout-recommendations.apply');
    Route::post('workout-recommendations/{recommendationId}/reject', RejectWorkoutRecommendationController::class)
        ->whereNumber('recommendationId')->name('api.workout-recommendations.reject');
});
