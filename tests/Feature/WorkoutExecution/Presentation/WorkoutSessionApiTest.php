<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionMutationInProgress;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionMutationLock;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->app->instance(
        WorkoutSessionMutationLock::class,
        new SynchronousWorkoutSessionMutationLock,
    );
    $this->app->instance(
        WorkoutClock::class,
        new FrozenWorkoutClock(new DateTimeImmutable(
            '2026-09-15 19:00:00',
            new DateTimeZone('Europe/Moscow'),
        )),
    );
});

$createProgram = static function (
    User $user,
    int $weekday = 1,
    string $name = 'Грудь и трицепс',
): array {
    $benchPress = Exercise::factory()->create(['name' => 'Жим лежа']);
    $triceps = Exercise::factory()->create(['name' => 'Разгибание на трицепс']);
    $program = TrainingProgramModel::query()->create([
        'user_id' => $user->id,
        'weekday' => $weekday,
        'name' => $name,
    ]);
    $program->plannedExercises()->createMany([
        [
            'exercise_id' => $benchPress->id,
            'sets' => 3,
            'repetitions_per_set' => 8,
            'working_weight_grams' => 90_000,
            'position' => 1,
        ],
        [
            'exercise_id' => $triceps->id,
            'sets' => 3,
            'repetitions_per_set' => 12,
            'working_weight_grams' => 36_000,
            'position' => 2,
        ],
    ]);

    return [$program, $benchPress, $triceps];
};

it('returns 401 for every workout session endpoint without a token', function (
    string $method,
    string $uri,
): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    'get active' => ['GET', '/api/workout-sessions/active'],
    'start' => ['PUT', '/api/workout-sessions/active'],
    'save sets' => ['PUT', '/api/workout-sessions/1/exercises/1/sets'],
    'complete exercise' => ['POST', '/api/workout-sessions/1/exercises/1/complete'],
    'skip exercise' => ['POST', '/api/workout-sessions/1/exercises/1/skip'],
    'reopen exercise' => ['POST', '/api/workout-sessions/1/exercises/1/reopen'],
    'complete session' => ['POST', '/api/workout-sessions/1/complete'],
    'cancel session' => ['POST', '/api/workout-sessions/1/cancel'],
]);

it('returns null data when the authenticated user has no active session', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/workout-sessions/active')
        ->assertOk()
        ->assertExactJson(['data' => null]);
});

it('starts a session and returns its complete plan and actual sets', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress] = $createProgram($user);
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.training_program_id', $program->id)
        ->assertJsonPath('data.program_name', 'Грудь и трицепс')
        ->assertJsonPath('data.scheduled_weekday', 1)
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.started_at', '2026-09-15T19:00:00+03:00')
        ->assertJsonPath('data.completed_at', null)
        ->assertJsonPath('data.cancelled_at', null)
        ->assertJsonPath('data.exercises.0.exercise_id', $benchPress->id)
        ->assertJsonPath('data.exercises.0.name', 'Жим лежа')
        ->assertJsonPath('data.exercises.0.planned_sets', 3)
        ->assertJsonPath('data.exercises.0.planned_repetitions_per_set', 8)
        ->assertJsonPath('data.exercises.0.planned_working_weight_kg', 90)
        ->assertJsonPath('data.exercises.0.sets.0.position', 1)
        ->assertJsonPath('data.exercises.0.sets.0.repetitions', 8)
        ->assertJsonPath('data.exercises.0.sets.0.working_weight_kg', 90)
        ->assertJsonMissingPath('data.user_id');
    $this->assertDatabaseCount('workout_sessions', 1);
    $this->assertDatabaseCount('workout_exercises', 2);
    $this->assertDatabaseCount('workout_sets', 6);
});

it('returns the same active session when start is retried for the same program', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program] = $createProgram($user);
    Sanctum::actingAs($user);
    $firstId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;

    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->assertJsonPath('data.id', $firstId);
    $this->assertDatabaseCount('workout_sessions', 1);
});

it('returns 409 when another program is started during an active session', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$firstProgram] = $createProgram($user, 1);
    [$secondProgram] = $createProgram($user, 2, 'Спина');
    Sanctum::actingAs($user);
    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $firstProgram->id,
    ])->assertOk();

    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $secondProgram->id,
    ])->assertConflict()->assertExactJson([
        'code' => 'active_workout_session_already_exists',
        'message' => 'Сначала завершите или отмените текущую тренировку.',
    ]);
    $this->assertDatabaseCount('workout_sessions', 1);
});

it('fully replaces and clears an exercise draft using array order as set positions', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress] = $createProgram($user);
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $uri = "/api/workout-sessions/{$sessionId}/exercises/{$benchPress->id}/sets";

    $this->putJson($uri, ['sets' => [
        ['repetitions' => 8, 'working_weight_kg' => 90],
        ['repetitions' => 7, 'working_weight_kg' => 100.25],
    ]])->assertOk()->assertJsonPath('data.exercises.0.sets', [
        ['position' => 1, 'repetitions' => 8, 'working_weight_kg' => 90],
        ['position' => 2, 'repetitions' => 7, 'working_weight_kg' => 100.25],
    ]);

    $this->putJson($uri, ['sets' => []])
        ->assertOk()
        ->assertJsonPath('data.exercises.0.sets', []);
});

it('completes an exercise idempotently only when repeated sets are identical', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress] = $createProgram($user);
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $uri = "/api/workout-sessions/{$sessionId}/exercises/{$benchPress->id}/complete";
    $sets = ['sets' => [
        ['repetitions' => 8, 'working_weight_kg' => 90],
        ['repetitions' => 8, 'working_weight_kg' => 100],
    ]];

    $this->postJson($uri, $sets)
        ->assertOk()
        ->assertJsonPath('data.exercises.0.status', 'completed');
    $this->postJson($uri, $sets)->assertOk();
    $this->postJson($uri, ['sets' => [[
        'repetitions' => 5,
        'working_weight_kg' => 110,
    ]]])->assertConflict()->assertJsonPath('code', 'workout_exercise_not_editable');
});

it('skips an exercise idempotently and restores planned sets when reopened', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress] = $createProgram($user);
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $uri = "/api/workout-sessions/{$sessionId}/exercises/{$benchPress->id}";

    $this->postJson($uri.'/skip')
        ->assertOk()
        ->assertJsonPath('data.exercises.0.status', 'skipped')
        ->assertJsonPath('data.exercises.0.sets', []);
    $this->postJson($uri.'/skip')->assertOk();
    $this->postJson($uri.'/reopen')
        ->assertOk()
        ->assertJsonPath('data.exercises.0.status', 'pending')
        ->assertJsonCount(3, 'data.exercises.0.sets');
});

it('completes a resolved session idempotently and preserves its first completion time', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress, $triceps] = $createProgram($user);
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$benchPress->id}/complete", [
        'sets' => [['repetitions' => 8, 'working_weight_kg' => 90]],
    ])->assertOk();
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$triceps->id}/skip")
        ->assertOk();

    $this->postJson("/api/workout-sessions/{$sessionId}/complete")
        ->assertOk()
        ->assertJsonPath('data.completed_at', '2026-09-15T19:00:00+03:00');
    $this->app->instance(
        WorkoutClock::class,
        new FrozenWorkoutClock(new DateTimeImmutable(
            '2026-09-15 20:00:00',
            new DateTimeZone('Europe/Moscow'),
        )),
    );
    $this->postJson("/api/workout-sessions/{$sessionId}/complete")
        ->assertOk()
        ->assertJsonPath('data.completed_at', '2026-09-15T19:00:00+03:00');
    $this->getJson('/api/workout-sessions/active')
        ->assertOk()
        ->assertExactJson(['data' => null]);
});

it('returns 409 when a session is completed with pending exercises', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program] = $createProgram($user);
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;

    $this->postJson("/api/workout-sessions/{$sessionId}/complete")
        ->assertConflict()
        ->assertExactJson([
            'code' => 'workout_session_has_pending_exercises',
            'message' => 'Завершите или пропустите все упражнения.',
        ]);
});

it('cancels a session idempotently and permits starting another program', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$firstProgram] = $createProgram($user, 1);
    [$secondProgram] = $createProgram($user, 2, 'Спина');
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $firstProgram->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;

    $this->postJson("/api/workout-sessions/{$sessionId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancelled_at', '2026-09-15T19:00:00+03:00');
    $this->postJson("/api/workout-sessions/{$sessionId}/cancel")->assertOk();
    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $secondProgram->id,
    ])->assertOk()->assertJsonPath('data.training_program_id', $secondProgram->id);
    $this->assertDatabaseCount('workout_sessions', 2);
});

it('validates identifiers, set limits and working values before calling Application', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress] = $createProgram($user);
    Sanctum::actingAs($user);
    $this->putJson('/api/workout-sessions/active', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['training_program_id']);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $uri = "/api/workout-sessions/{$sessionId}/exercises/{$benchPress->id}";

    $this->putJson($uri.'/sets', ['sets' => array_fill(0, 101, [
        'repetitions' => 8,
        'working_weight_kg' => 90,
    ])])->assertUnprocessable()->assertJsonValidationErrors(['sets']);
    $this->postJson($uri.'/complete', ['sets' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sets']);
    $this->putJson($uri.'/sets', ['sets' => [[
        'repetitions' => 0,
        'working_weight_kg' => 1.255,
    ]]])->assertUnprocessable()->assertJsonValidationErrors([
        'sets.0.repetitions',
        'sets.0.working_weight_kg',
    ]);
    $this->postJson('/api/workout-sessions/0/cancel')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_session_id']);
});

it('rejects associative set objects and weights outside the safe conversion range', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress] = $createProgram($user);
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $uri = "/api/workout-sessions/{$sessionId}/exercises/{$benchPress->id}/sets";

    $this->putJson($uri, ['sets' => [
        'first' => ['repetitions' => 8, 'working_weight_kg' => 90],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['sets']);

    $this->putJson($uri, ['sets' => [[
        'repetitions' => 8,
        'working_weight_kg' => '9223372036854776',
    ]]])->assertUnprocessable()->assertJsonValidationErrors([
        'sets.0.working_weight_kg',
    ]);
});

it('does not expose or mutate another user session', function () use ($createProgram): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    [$program, $benchPress] = $createProgram($owner);
    Sanctum::actingAs($owner);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    Sanctum::actingAs($otherUser);

    $this->putJson("/api/workout-sessions/{$sessionId}/exercises/{$benchPress->id}/sets", [
        'sets' => [['repetitions' => 1, 'working_weight_kg' => 0]],
    ])->assertNotFound()->assertExactJson([
        'code' => 'workout_session_not_found',
        'message' => 'Тренировочная сессия не найдена.',
    ]);
    $this->assertDatabaseHas('workout_sets', [
        'position' => 1,
        'repetitions' => 8,
        'working_weight_grams' => 90_000,
    ]);
});

it('returns 409 with a stable code while a workout mutation is locked', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program] = $createProgram($user);
    Sanctum::actingAs($user);
    $this->app->instance(
        WorkoutSessionMutationLock::class,
        new class implements WorkoutSessionMutationLock
        {
            public function execute(UserId $userId, Closure $callback): mixed
            {
                throw new WorkoutSessionMutationInProgress;
            }
        },
    );

    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertConflict()->assertExactJson([
        'code' => 'workout_session_mutation_in_progress',
        'message' => 'Изменение тренировки уже выполняется. Повторите попытку.',
    ]);
    $this->assertDatabaseCount('workout_sessions', 0);
});
