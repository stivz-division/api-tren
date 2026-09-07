<?php

namespace App\Providers;

use App\Services\TelegramWebAppService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            TelegramWebAppService::class,
            static fn (): TelegramWebAppService => new TelegramWebAppService(
                botToken: (string) config('services.telegram.bot_token', ''),
                authDateTtl: (int) config('services.telegram.auth_date_ttl', 300),
                authDateFutureLeeway: (int) config('services.telegram.auth_date_future_leeway', 30),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for(
            'telegram-auth',
            static fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()),
        );
    }
}
