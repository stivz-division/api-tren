<?php

use App\Services\TelegramWebAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

const TELEGRAM_SERVICE_TEST_BOT_TOKEN = 'test-token';
const TELEGRAM_SERVICE_TEST_NOW = 1_700_000_000;

$signTelegramServiceData = static function (array $fields, string $botToken = TELEGRAM_SERVICE_TEST_BOT_TOKEN): string {
    ksort($fields, SORT_STRING);

    $dataCheckString = implode("\n", array_map(
        static fn (string $key, string $value): string => $key.'='.$value,
        array_keys($fields),
        array_values($fields),
    ));
    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

    $query = http_build_query($fields, encoding_type: PHP_QUERY_RFC3986);

    return $query.'&hash='.$hash;
};

$validTelegramServiceFields = static fn (int $authDate = TELEGRAM_SERVICE_TEST_NOW): array => [
    'auth_date' => (string) $authDate,
    'query_id' => 'AAE0m7oLAAAAADSbugtKyT4p',
    'user' => json_encode([
        'id' => 111111111,
        'first_name' => 'Evgen',
        'last_name' => 'Example',
        'username' => 'evgen',
        'language_code' => 'en',
    ], JSON_THROW_ON_ERROR),
];

beforeEach(function (): void {
    config()->set([
        'services.telegram.bot_token' => TELEGRAM_SERVICE_TEST_BOT_TOKEN,
        'services.telegram.auth_date_ttl' => 300,
        'services.telegram.auth_date_future_leeway' => 30,
    ]);

    $this->travelTo(Carbon::createFromTimestamp(TELEGRAM_SERVICE_TEST_NOW));
});

it('validates the fixed Telegram vector and returns only persisted profile fields', function () {
    $this->travelTo(Carbon::createFromTimestamp(1_698_814_911 + 100));
    $initData = trim(file_get_contents(base_path('tests/Fixtures/Telegram/valid-init-data.txt')));

    $result = app(TelegramWebAppService::class)->validate($initData);

    expect($result)->toBe([
        'telegram_id' => 111111111,
        'username' => 'micromagicman',
        'first_name' => 'Evgen',
        'last_name' => 'Evgen',
        'language_code' => 'xx',
    ])->not->toHaveKeys(['is_premium', 'allows_write_to_pm']);
});

it('accepts absent optional profile fields', function () use ($signTelegramServiceData) {
    $initData = $signTelegramServiceData([
        'auth_date' => (string) TELEGRAM_SERVICE_TEST_NOW,
        'user' => json_encode([
            'id' => 222222222,
            'first_name' => 'Telegram User',
        ], JSON_THROW_ON_ERROR),
    ]);

    $result = app(TelegramWebAppService::class)->validate($initData);

    expect($result)->toBe([
        'telegram_id' => 222222222,
        'username' => null,
        'first_name' => 'Telegram User',
        'last_name' => null,
        'language_code' => null,
    ]);
});

it('rejects invalid signed or malformed init data', function (string $initData) {
    expect(app(TelegramWebAppService::class)->validate($initData))->toBeNull();
})->with(function () use ($signTelegramServiceData, $validTelegramServiceFields): array {
    $validFields = $validTelegramServiceFields();
    $validInitData = $signTelegramServiceData($validFields);
    $withoutHash = http_build_query($validFields, encoding_type: PHP_QUERY_RFC3986);
    $withoutAuthDate = $validFields;
    unset($withoutAuthDate['auth_date']);
    $withoutUser = $validFields;
    unset($withoutUser['user']);

    return [
        'tampered user' => [str_replace('Evgen', 'Mallory', $validInitData)],
        'tampered auth date' => [str_replace((string) TELEGRAM_SERVICE_TEST_NOW, (string) (TELEGRAM_SERVICE_TEST_NOW - 1), $validInitData)],
        'missing hash' => [$withoutHash],
        'missing auth date' => [$signTelegramServiceData($withoutAuthDate)],
        'missing user' => [$signTelegramServiceData($withoutUser)],
        'malformed percent encoding' => ['auth_date=1700000000&user=%ZZ&hash='.str_repeat('a', 64)],
        'malformed pair' => ['auth_date=1700000000&broken&hash='.str_repeat('a', 64)],
        'duplicate decoded key' => [$validInitData.'&%75ser=duplicate'],
        'short hash' => [preg_replace('/hash=[a-f0-9]+$/', 'hash=abc', $validInitData)],
        'uppercase hash' => [preg_replace_callback('/hash=([a-f0-9]+)$/', static fn (array $matches): string => 'hash='.strtoupper($matches[1]), $validInitData)],
        'empty input' => [''],
        'oversized input' => [str_repeat('a', 10_241)],
    ];
});

it('rejects signed invalid Telegram user objects', function (string $encodedUser) use ($signTelegramServiceData) {
    $initData = $signTelegramServiceData([
        'auth_date' => (string) TELEGRAM_SERVICE_TEST_NOW,
        'user' => $encodedUser,
    ]);

    expect(app(TelegramWebAppService::class)->validate($initData))->toBeNull();
})->with([
    'malformed json' => ['{invalid'],
    'json array' => ['[]'],
    'string id' => [json_encode(['id' => '111111111', 'first_name' => 'Evgen'], JSON_THROW_ON_ERROR)],
    'zero id' => [json_encode(['id' => 0, 'first_name' => 'Evgen'], JSON_THROW_ON_ERROR)],
    'id above JavaScript safe integer range' => [json_encode(['id' => 4_503_599_627_370_496, 'first_name' => 'Evgen'], JSON_THROW_ON_ERROR)],
    'missing first name' => [json_encode(['id' => 111111111], JSON_THROW_ON_ERROR)],
    'non-string first name' => [json_encode(['id' => 111111111, 'first_name' => 123], JSON_THROW_ON_ERROR)],
    'blank first name' => [json_encode(['id' => 111111111, 'first_name' => '   '], JSON_THROW_ON_ERROR)],
    'long first name' => [json_encode(['id' => 111111111, 'first_name' => str_repeat('a', 256)], JSON_THROW_ON_ERROR)],
    'non-string username' => [json_encode(['id' => 111111111, 'first_name' => 'Evgen', 'username' => true], JSON_THROW_ON_ERROR)],
    'long username' => [json_encode(['id' => 111111111, 'first_name' => 'Evgen', 'username' => str_repeat('a', 256)], JSON_THROW_ON_ERROR)],
    'non-string last name' => [json_encode(['id' => 111111111, 'first_name' => 'Evgen', 'last_name' => []], JSON_THROW_ON_ERROR)],
    'long last name' => [json_encode(['id' => 111111111, 'first_name' => 'Evgen', 'last_name' => str_repeat('a', 256)], JSON_THROW_ON_ERROR)],
    'non-string language code' => [json_encode(['id' => 111111111, 'first_name' => 'Evgen', 'language_code' => 1], JSON_THROW_ON_ERROR)],
    'long language code' => [json_encode(['id' => 111111111, 'first_name' => 'Evgen', 'language_code' => str_repeat('a', 17)], JSON_THROW_ON_ERROR)],
]);

it('enforces auth date boundaries', function (int $authDate, bool $isValid) use ($signTelegramServiceData, $validTelegramServiceFields) {
    $initData = $signTelegramServiceData($validTelegramServiceFields($authDate));

    $result = app(TelegramWebAppService::class)->validate($initData);

    expect($result !== null)->toBe($isValid);
})->with([
    'exact ttl boundary' => [TELEGRAM_SERVICE_TEST_NOW - 300, true],
    'one second beyond ttl' => [TELEGRAM_SERVICE_TEST_NOW - 301, false],
    'exact future leeway boundary' => [TELEGRAM_SERVICE_TEST_NOW + 30, true],
    'one second beyond future leeway' => [TELEGRAM_SERVICE_TEST_NOW + 31, false],
]);

it('ignores signed unexpected and premium fields', function () use ($signTelegramServiceData, $validTelegramServiceFields) {
    $fields = $validTelegramServiceFields();
    $user = json_decode($fields['user'], true, flags: JSON_THROW_ON_ERROR);
    $user['is_premium'] = true;
    $user['allows_write_to_pm'] = true;
    $user['unexpected'] = 'ignored';
    $fields['user'] = json_encode($user, JSON_THROW_ON_ERROR);

    $result = app(TelegramWebAppService::class)->validate($signTelegramServiceData($fields));

    expect($result)->toHaveKeys(['telegram_id', 'username', 'first_name', 'last_name', 'language_code'])
        ->not->toHaveKeys(['is_premium', 'allows_write_to_pm', 'unexpected']);
});

it('fails closed when the Telegram bot token is empty', function () use ($signTelegramServiceData, $validTelegramServiceFields) {
    config()->set('services.telegram.bot_token', '');

    app(TelegramWebAppService::class)->validate($signTelegramServiceData($validTelegramServiceFields()));
})->throws(LogicException::class, 'Telegram Web App authentication is not configured.');

it('does not log sensitive input when validation fails', function () {
    $logger = Log::spy();
    $sensitiveInput = 'user=sensitive-profile&auth_date=1700000000&hash='.str_repeat('f', 64);

    app(TelegramWebAppService::class)->validate($sensitiveInput);

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
        $logger->shouldNotHaveReceived($level);
    }
});
