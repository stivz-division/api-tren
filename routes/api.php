<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\ValidateTelegramWebAppData;
use Illuminate\Support\Facades\Route;

Route::post('auth', AuthController::class)
    ->middleware([
        'throttle:telegram-auth',
        ValidateTelegramWebAppData::class,
    ])
    ->name('api.auth');

require base_path('app/WorkoutPlanning/Presentation/Routes/api.php');
require base_path('app/WorkoutExecution/Presentation/Routes/api.php');
