<?php

use App\Models\User;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

$createSession = static function (
    User $user,
    string $status,
    string $startedAt,
    string $programName,
): WorkoutSessionModel {
    $resolvedAt = (new DateTimeImmutable($startedAt))->modify('+1 hour');
    $session = WorkoutSessionModel::query()->create([
        'user_id' => $user->id,
        'training_program_id' => 71,
        'training_program_name' => $programName,
        'scheduled_weekday' => 1,
        'status' => $status,
        'started_at' => new DateTimeImmutable($startedAt),
        'completed_at' => $status === 'completed' ? $resolvedAt : null,
        'cancelled_at' => $status === 'cancelled' ? $resolvedAt : null,
    ]);
    $exercise = $session->workoutExercises()->create([
        'exercise_id' => 501,
        'exercise_name' => 'Жим лежа',
        'position' => 1,
        'status' => $status === 'completed' ? 'completed' : 'pending',
    ]);
    $exercise->plannedSets()->create([
        'position' => 1,
        'repetitions' => 8,
        'working_weight_grams' => 90_000,
    ]);
    $exercise->workoutSets()->create([
        'position' => 1,
        'repetitions' => 8,
        'working_weight_grams' => 92_500,
    ]);

    return $session;
};

it('returns 401 when workout history is requested without a token', function (): void {
    $this->getJson('/api/workout-sessions')->assertUnauthorized();
});

it('returns only the authenticated user terminal sessions newest first across cursor pages', function () use ($createSession): void {
    $user = User::factory()->create();
    $anotherUser = User::factory()->create();
    $cancelledSession = $createSession(
        $user,
        'cancelled',
        '2026-09-12 09:00:00 UTC',
        'Отменённая тренировка',
    );
    $completedSession = $createSession(
        $user,
        'completed',
        '2026-09-13 09:00:00 UTC',
        'Завершённая тренировка',
    );
    $createSession($user, 'in_progress', '2026-09-14 09:00:00 UTC', 'Активная тренировка');
    $createSession($anotherUser, 'completed', '2026-09-15 09:00:00 UTC', 'Чужая тренировка');
    Sanctum::actingAs($user);

    $firstPage = $this->getJson('/api/workout-sessions?per_page=1');

    $firstPage
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $completedSession->id)
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.0.started_at', '2026-09-13T12:00:00+03:00')
        ->assertJsonPath('data.0.exercises.0.name', 'Жим лежа')
        ->assertJsonPath('data.0.exercises.0.sets.0.working_weight_kg', 92.5)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.prev_cursor', null)
        ->assertJsonPath('links.prev', null);
    $nextCursor = expect($firstPage->json('meta.next_cursor'))->toBeString()->value;
    expect($firstPage->json('links.next'))->toBeString();

    $secondPage = $this->getJson('/api/workout-sessions?per_page=1&cursor='.urlencode($nextCursor));

    $secondPage
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cancelledSession->id)
        ->assertJsonPath('data.0.status', 'cancelled')
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('links.next', null);
    $previousCursor = expect($secondPage->json('meta.prev_cursor'))->toBeString()->value;
    expect($secondPage->json('links.prev'))->toBeString();

    $previousPage = $this->getJson(
        '/api/workout-sessions?per_page=1&cursor='.urlencode($previousCursor),
    );

    $previousPage
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $completedSession->id);
});

it('uses the session id as a stable cursor tie breaker', function () use ($createSession): void {
    $user = User::factory()->create();
    $firstSession = $createSession($user, 'completed', '2026-09-13 09:00:00 UTC', 'Первая');
    $secondSession = $createSession($user, 'completed', '2026-09-13 09:00:00 UTC', 'Вторая');
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/workout-sessions?per_page=2');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $secondSession->id)
        ->assertJsonPath('data.1.id', $firstSession->id);
});

it('returns 422 when the requested history page size is outside its limits', function (int $perPage): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/workout-sessions?per_page={$perPage}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
})->with([
    'zero' => 0,
    'above maximum' => 51,
]);

it('uses 15 as the default history page size', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/workout-sessions')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.per_page', 15);
});

it('accepts 50 as the maximum history page size', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/workout-sessions?per_page=50')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50);
});

it('returns 422 when the cursor is invalid', function (string $cursor): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/workout-sessions?cursor='.$cursor)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
})->with([
    'missing ordering fields' => 'eyJmb28iOiJiYXIiLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
    'invalid started at' => 'eyJzdGFydGVkX2F0Ijoibm90LWEtZGF0ZSIsImlkIjoxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
    'null byte in started at' => 'eyJzdGFydGVkX2F0IjoiMjAyNi0wOS0xMyAwOTowMDowMFx1MDAwMCIsImlkIjoxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
]);
