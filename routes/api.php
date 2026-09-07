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
