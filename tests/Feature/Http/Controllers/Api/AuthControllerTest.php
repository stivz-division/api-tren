<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

const TELEGRAM_ENDPOINT_TEST_BOT_TOKEN = 'endpoint-test-token';
const TELEGRAM_ENDPOINT_TEST_NOW = 1_700_000_000;

$telegramEndpointInitData = static function (
    array $userOverrides = [],
    int $authDate = TELEGRAM_ENDPOINT_TEST_NOW,
): string {
    $fields = [
        'auth_date' => (string) $authDate,
        'query_id' => 'endpoint-query-id',
        'user' => json_encode(array_replace([
            'id' => 987654321,
            'first_name' => 'Telegram',
            'last_name' => 'User',
            'username' => 'telegram_user',
            'language_code' => 'en',
        ], $userOverrides), JSON_THROW_ON_ERROR),
    ];

    ksort($fields, SORT_STRING);

    $dataCheckString = implode("\n", array_map(
        static fn (string $key, string $value): string => $key.'='.$value,
        array_keys($fields),
        array_values($fields),
    ));
    $secretKey = hash_hmac('sha256', TELEGRAM_ENDPOINT_TEST_BOT_TOKEN, 'WebAppData', true);
    $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

    return http_build_query($fields, encoding_type: PHP_QUERY_RFC3986).'&hash='.$hash;
};

beforeEach(function (): void {
    config()->set([
        'services.telegram.bot_token' => TELEGRAM_ENDPOINT_TEST_BOT_TOKEN,
        'services.telegram.auth_date_ttl' => 300,
        'services.telegram.auth_date_future_leeway' => 30,
    ]);

    $this->travelTo(Carbon::createFromTimestamp(TELEGRAM_ENDPOINT_TEST_NOW));
});

it('returns a Bearer token and creates the Telegram user', function () use ($telegramEndpointInitData) {
    $initData = $telegramEndpointInitData([
        'is_premium' => true,
        'unexpected' => 'ignored',
    ]);

    $response = $this->postJson(route('api.auth'), ['init_data' => $initData]);

    $response
        ->assertOk()
        ->assertHeader('Cache-Control')
        ->assertExactJson([
            'token' => $response->json('token'),
            'token_type' => 'Bearer',
        ]);

    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
    $this->assertDatabaseHas('users', [
        'telegram_id' => 987654321,
        'username' => 'telegram_user',
        'first_name' => 'Telegram',
        'last_name' => 'User',
        'language_code' => 'en',
    ]);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('personal_access_tokens', 1);

    $user = User::query()->sole();
    $token = PersonalAccessToken::findToken($response->json('token'));

    expect($user->last_authenticated_at?->getTimestamp())->toBe(TELEGRAM_ENDPOINT_TEST_NOW)
        ->and($token?->tokenable->is($user))->toBeTrue()
        ->and(Schema::hasColumn('users', 'is_premium'))->toBeFalse();
});

it('updates an existing Telegram profile without creating a duplicate user', function () use ($telegramEndpointInitData) {
    $user = User::factory()->create([
        'telegram_id' => 987654321,
        'username' => 'old_username',
        'first_name' => 'Old',
        'last_name' => null,
        'language_code' => null,
        'last_authenticated_at' => null,
    ]);
    $initData = $telegramEndpointInitData([
        'username' => 'updated_username',
        'first_name' => 'Updated',
        'last_name' => 'Profile',
        'language_code' => 'de',
    ]);

    $response = $this->postJson(route('api.auth'), ['init_data' => $initData]);

    $response->assertOk();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'telegram_id' => 987654321,
        'username' => 'updated_username',
        'first_name' => 'Updated',
        'last_name' => 'Profile',
        'language_code' => 'de',
    ]);
    expect($user->fresh()->last_authenticated_at?->getTimestamp())->toBe(TELEGRAM_ENDPOINT_TEST_NOW);
});

it('returns generic 401 without persisting data for invalid credentials', function (array $payload) {
    $response = $this->postJson(route('api.auth'), $payload);

    $response
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'missing init data' => [[]],
    'non-string init data' => [['init_data' => ['not-a-string']]],
    'invalid signature' => [['init_data' => 'auth_date=1700000000&user=%7B%7D&hash='.str_repeat('a', 64)]],
    'malformed query string' => [['init_data' => 'auth_date=1700000000&broken']],
]);

it('revokes the previous token when the user authenticates again', function () use ($telegramEndpointInitData) {
    $initData = $telegramEndpointInitData();

    $firstResponse = $this->postJson(route('api.auth'), ['init_data' => $initData]);
    $secondResponse = $this->postJson(route('api.auth'), ['init_data' => $initData]);

    $firstResponse->assertOk();
    $secondResponse->assertOk();
    $firstToken = $firstResponse->json('token');
    $secondToken = $secondResponse->json('token');
    $user = User::query()->sole();

    expect($secondToken)->not->toBe($firstToken)
        ->and(PersonalAccessToken::findToken($firstToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($secondToken)?->tokenable->is($user))->toBeTrue();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('returns 429 after ten authentication attempts from one IP', function () {
    $sensitiveInitData = 'auth_date=1700000000&user=sensitive-profile&hash='.str_repeat('f', 64);

    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('api.auth'), ['init_data' => $sensitiveInitData])
            ->assertUnauthorized();
    }

    $response = $this->postJson(route('api.auth'), ['init_data' => $sensitiveInitData]);

    $response->assertTooManyRequests();
    expect($response->getContent())
        ->not->toContain($sensitiveInitData)
        ->not->toContain('sensitive-profile')
        ->not->toContain(str_repeat('f', 64));
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});
