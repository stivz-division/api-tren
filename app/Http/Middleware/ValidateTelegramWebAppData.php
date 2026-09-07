<?php

namespace App\Http\Middleware;

use App\Services\TelegramWebAppService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateTelegramWebAppData
{
    public const string TELEGRAM_USER_ATTRIBUTE = 'telegram_user';

    public function __construct(private TelegramWebAppService $telegramWebAppService) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $initData = $request->input('init_data');

        if (! is_string($initData)) {
            throw new AuthenticationException;
        }

        $telegramUser = $this->telegramWebAppService->validate($initData);

        if ($telegramUser === null) {
            throw new AuthenticationException;
        }

        $request->attributes->set(self::TELEGRAM_USER_ATTRIBUTE, $telegramUser);

        return $next($request);
    }
}
