<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ValidateTelegramWebAppData;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AuthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $telegramUser = $request->attributes->get(ValidateTelegramWebAppData::TELEGRAM_USER_ATTRIBUTE);

        if (! is_array($telegramUser)) {
            throw new LogicException('Validated Telegram user data is unavailable.');
        }

        /** @var array{telegram_id: int, username: string|null, first_name: string, last_name: string|null, language_code: string|null} $telegramUser */
        $plainTextToken = DB::transaction(function () use ($telegramUser): string {
            User::query()->upsert(
                [[
                    'telegram_id' => $telegramUser['telegram_id'],
                    'username' => $telegramUser['username'],
                    'first_name' => $telegramUser['first_name'],
                    'last_name' => $telegramUser['last_name'],
                    'language_code' => $telegramUser['language_code'],
                    'last_authenticated_at' => now(),
                ]],
                ['telegram_id'],
                [
                    'username',
                    'first_name',
                    'last_name',
                    'language_code',
                    'last_authenticated_at',
                ],
            );

            $user = User::query()
                ->where('telegram_id', $telegramUser['telegram_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $user->tokens()->delete();

            return $user->createToken('telegram-auth', ['*'])->plainTextToken;
        }, attempts: 3);

        return response()
            ->json([
                'token' => $plainTextToken,
                'token_type' => 'Bearer',
            ])
            ->header('Cache-Control', 'no-store');
    }
}
