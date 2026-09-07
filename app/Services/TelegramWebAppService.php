<?php

namespace App\Services;

use Illuminate\Support\Str;
use JsonException;
use LogicException;
use stdClass;

final readonly class TelegramWebAppService
{
    private const int MAX_INIT_DATA_BYTES = 10_240;

    private const int MAX_TELEGRAM_ID = 4_503_599_627_370_495;

    public function __construct(
        private string $botToken,
        private int $authDateTtl,
        private int $authDateFutureLeeway,
    ) {}

    /**
     * @return array{
     *     telegram_id: int,
     *     username: string|null,
     *     first_name: string,
     *     last_name: string|null,
     *     language_code: string|null,
     * }|null
     */
    public function validate(string $initData): ?array
    {
        $this->ensureConfigured();

        $fields = $this->parseFields($initData);

        if ($fields === null || ! $this->hasValidSignature($fields)) {
            return null;
        }

        $authDate = $this->parseAuthDate($fields['auth_date'] ?? null);

        if ($authDate === null || ! $this->isFresh($authDate)) {
            return null;
        }

        return $this->normalizeUser($fields['user'] ?? null);
    }

    private function ensureConfigured(): void
    {
        if ($this->botToken === '' || $this->authDateTtl <= 0 || $this->authDateFutureLeeway < 0) {
            throw new LogicException('Telegram Web App authentication is not configured.');
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function parseFields(string $initData): ?array
    {
        if ($initData === '' || strlen($initData) > self::MAX_INIT_DATA_BYTES) {
            return null;
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $initData) === 1) {
            return null;
        }

        $fields = [];

        foreach (explode('&', $initData) as $pair) {
            $separatorPosition = strpos($pair, '=');

            if ($pair === '' || $separatorPosition === false) {
                return null;
            }

            $key = urldecode(substr($pair, 0, $separatorPosition));
            $value = urldecode(substr($pair, $separatorPosition + 1));

            if ($key === '' || array_key_exists($key, $fields)) {
                return null;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function hasValidSignature(array $fields): bool
    {
        $hash = $fields['hash'] ?? null;

        if ($hash === null || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            return false;
        }

        if (! array_key_exists('auth_date', $fields) || ! array_key_exists('user', $fields)) {
            return false;
        }

        unset($fields['hash']);
        ksort($fields, SORT_STRING);

        $dataCheckString = implode("\n", array_map(
            static fn (string $key, string $value): string => $key.'='.$value,
            array_keys($fields),
            array_values($fields),
        ));
        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($expectedHash, $hash);
    }

    private function parseAuthDate(?string $authDate): ?int
    {
        if ($authDate === null || preg_match('/\A[0-9]+\z/D', $authDate) !== 1) {
            return null;
        }

        $timestamp = filter_var($authDate, FILTER_VALIDATE_INT);

        return is_int($timestamp) && $timestamp > 0 ? $timestamp : null;
    }

    private function isFresh(int $authDate): bool
    {
        $now = now()->getTimestamp();

        return $authDate <= $now + $this->authDateFutureLeeway
            && $now - $authDate <= $this->authDateTtl;
    }

    /**
     * @return array{
     *     telegram_id: int,
     *     username: string|null,
     *     first_name: string,
     *     last_name: string|null,
     *     language_code: string|null,
     * }|null
     */
    private function normalizeUser(?string $encodedUser): ?array
    {
        if ($encodedUser === null) {
            return null;
        }

        try {
            $decodedUser = json_decode($encodedUser, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $decodedUser instanceof stdClass) {
            return null;
        }

        $user = get_object_vars($decodedUser);
        $telegramId = $user['id'] ?? null;
        $firstName = $user['first_name'] ?? null;

        if (! is_int($telegramId) || $telegramId <= 0 || $telegramId > self::MAX_TELEGRAM_ID) {
            return null;
        }

        if (! is_string($firstName) || trim($firstName) === '' || Str::length($firstName) > 255) {
            return null;
        }

        if (! $this->hasValidOptionalString($user, 'username', 255)
            || ! $this->hasValidOptionalString($user, 'last_name', 255)
            || ! $this->hasValidOptionalString($user, 'language_code', 16)) {
            return null;
        }

        return [
            'telegram_id' => $telegramId,
            'username' => $user['username'] ?? null,
            'first_name' => $firstName,
            'last_name' => $user['last_name'] ?? null,
            'language_code' => $user['language_code'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function hasValidOptionalString(array $user, string $field, int $maxLength): bool
    {
        if (! array_key_exists($field, $user)) {
            return true;
        }

        return is_string($user[$field]) && Str::length($user[$field]) <= $maxLength;
    }
}
