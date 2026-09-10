<?php

use App\WorkoutPlanning\Presentation\Http\Controllers\DeleteTrainingProgramController;
use App\WorkoutPlanning\Presentation\Http\Controllers\GetTrainingProgramForWeekdayController;
use App\WorkoutPlanning\Presentation\Http\Controllers\StoreTrainingProgramController;
use App\WorkoutPlanning\Presentation\Http\Controllers\UpdateTrainingProgramController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('training-programs', StoreTrainingProgramController::class)
        ->name('api.training-programs.store');
    Route::put('training-programs/{trainingProgramId}', UpdateTrainingProgramController::class)
        ->whereNumber('trainingProgramId')
        ->name('api.training-programs.update');
    Route::delete('training-programs/{trainingProgramId}', DeleteTrainingProgramController::class)
        ->whereNumber('trainingProgramId')
        ->name('api.training-programs.destroy');
    Route::get('training-programs/weekdays/{weekday}', GetTrainingProgramForWeekdayController::class)
        ->whereNumber('weekday')
        ->name('api.training-programs.weekdays.show');
});
