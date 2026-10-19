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
            '2026-09-15 16:00:00',
            new DateTimeZone('UTC'),
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
    $benchPressPlan = $program->plannedExercises()->create([
        'exercise_id' => $benchPress->id,
        'position' => 1,
    ]);
    $benchPressPlan->plannedSets()->createMany([
        ['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 80_000],
        ['position' => 2, 'repetitions' => 8, 'working_weight_grams' => 90_000],
        ['position' => 3, 'repetitions' => 6, 'working_weight_grams' => 100_000],
    ]);
    $tricepsPlan = $program->plannedExercises()->create([
        'exercise_id' => $triceps->id,
        'position' => 2,
    ]);
    $tricepsPlan->plannedSets()->createMany([
        ['position' => 1, 'repetitions' => 12, 'working_weight_grams' => 36_000],
        ['position' => 2, 'repetitions' => 12, 'working_weight_grams' => 36_000],
        ['position' => 3, 'repetitions' => 12, 'working_weight_grams' => 36_000],
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
    'replace exercise' => ['POST', '/api/workout-sessions/1/exercises/1/replace'],
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

it('starts a session with immutable planned sets and matching actual sets', function () use ($createProgram): void {
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
        ->assertJsonPath('data.started_at', '2026-09-15T16:00:00+00:00')
        ->assertJsonPath('data.completed_at', null)
        ->assertJsonPath('data.cancelled_at', null)
        ->assertJsonPath('data.exercises.0.exercise_id', $benchPress->id)
        ->assertJsonPath('data.exercises.0.name', 'Жим лежа')
        ->assertJsonPath('data.exercises.0.planned_sets', [
            ['position' => 1, 'repetitions' => 10, 'working_weight_kg' => 80],
            ['position' => 2, 'repetitions' => 8, 'working_weight_kg' => 90],
            ['position' => 3, 'repetitions' => 6, 'working_weight_kg' => 100],
        ])
        ->assertJsonPath('data.exercises.0.sets.0.position', 1)
        ->assertJsonPath('data.exercises.0.sets.0.repetitions', 10)
        ->assertJsonPath('data.exercises.0.sets.0.working_weight_kg', 80)
        ->assertJsonMissingPath('data.user_id');
    $this->assertDatabaseCount('workout_sessions', 1);
    $this->assertDatabaseCount('workout_exercises', 2);
    $this->assertDatabaseCount('workout_planned_sets', 6);
    $this->assertDatabaseCount('workout_sets', 6);
});

it('keeps the per-set snapshot after the source training program changes', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $benchPress] = $createProgram($user);
    Sanctum::actingAs($user);
    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk();
    $plannedExercise = $program->plannedExercises()
        ->where('exercise_id', $benchPress->id)
        ->firstOrFail();

    $plannedExercise->plannedSets()->delete();
    $plannedExercise->plannedSets()->create([
        'position' => 1,
        'repetitions' => 1,
        'working_weight_grams' => 200_000,
    ]);

    $this->getJson('/api/workout-sessions/active')
        ->assertOk()
        ->assertJsonPath('data.exercises.0.planned_sets', [
            ['position' => 1, 'repetitions' => 10, 'working_weight_kg' => 80],
            ['position' => 2, 'repetitions' => 8, 'working_weight_kg' => 90],
            ['position' => 3, 'repetitions' => 6, 'working_weight_kg' => 100],
        ]);
    $this->assertDatabaseCount('workout_planned_sets', 6);
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

it('starts the selected same-day program and prevents simultaneous sessions', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$firstProgram, $firstExercise] = $createProgram($user, 1, 'Утро');
    [$secondProgram, $secondExercise] = $createProgram($user, 1, 'Вечер');
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $secondProgram->id,
    ])->assertOk()
        ->assertJsonPath('data.training_program_id', $secondProgram->id)
        ->assertJsonPath('data.program_name', 'Вечер')
        ->assertJsonPath('data.exercises.0.exercise_id', $secondExercise->id)
        ->json('data.id'))->toBeInt()->value;

    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $firstProgram->id,
    ])->assertConflict()->assertExactJson([
        'code' => 'active_workout_session_already_exists',
        'message' => 'Сначала завершите или отмените текущую тренировку.',
    ]);
    $this->assertDatabaseCount('workout_sessions', 1);

    $this->postJson('/api/workout-sessions/'.$sessionId.'/cancel')->assertOk();
    $this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $firstProgram->id,
    ])->assertOk()
        ->assertJsonPath('data.training_program_id', $firstProgram->id)
        ->assertJsonPath('data.program_name', 'Утро')
        ->assertJsonPath('data.exercises.0.exercise_id', $firstExercise->id);
    $this->assertDatabaseCount('workout_sessions', 2);
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
        ->assertJsonPath('data.completed_at', '2026-09-15T16:00:00+00:00');
    $this->app->instance(
        WorkoutClock::class,
        new FrozenWorkoutClock(new DateTimeImmutable(
            '2026-09-15 17:00:00',
            new DateTimeZone('UTC'),
        )),
    );
    $this->postJson("/api/workout-sessions/{$sessionId}/complete")
        ->assertOk()
        ->assertJsonPath('data.completed_at', '2026-09-15T16:00:00+00:00');
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
        ->assertJsonPath('data.cancelled_at', '2026-09-15T16:00:00+00:00');
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
        'repetitions' => 10,
        'working_weight_grams' => 80_000,
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

it('replaces an exercise with a complete session response and preserves its source snapshot', function (?bool $replaceInPlan) use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $source, $other] = $createProgram($user);
    $candidate = Exercise::factory()->create(['name' => 'Новый жим']);
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $payload = [
        'request_id' => '550E8400-E29B-41D4-A716-446655440000',
        'replacement_exercise_id' => $candidate->id,
        'sets' => [['repetitions' => 11, 'working_weight_kg' => 25.25]],
    ];
    if ($replaceInPlan !== null) {
        $payload['replace_in_plan'] = $replaceInPlan;
    }

    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$source->id}/replace", $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $sessionId)
        ->assertJsonCount(2, 'data.exercises')
        ->assertJsonPath('data.exercises.0.exercise_id', $source->id)
        ->assertJsonPath('data.exercises.0.name', $source->name)
        ->assertJsonPath('data.exercises.0.planned_sets.0.working_weight_kg', 80)
        ->assertJsonPath('data.exercises.0.effective_exercise.exercise_id', $candidate->id)
        ->assertJsonPath('data.exercises.0.effective_exercise.name', $candidate->name)
        ->assertJsonPath('data.exercises.0.effective_exercise.planned_sets', [
            ['position' => 1, 'repetitions' => 11, 'working_weight_kg' => 25.25],
        ])
        ->assertJsonPath('data.exercises.0.replacement', [
            'request_id' => strtolower($payload['request_id']),
            'replace_in_plan' => $replaceInPlan ?? false,
        ])
        ->assertJsonPath('data.exercises.0.included_in_analysis', $replaceInPlan ?? false)
        ->assertJsonPath('data.exercises.0.status', 'pending')
        ->assertJsonPath('data.exercises.1.effective_exercise.exercise_id', $other->id)
        ->assertJsonPath('data.exercises.1.replacement', null)
        ->assertJsonPath('data.exercises.1.included_in_analysis', true);
    $this->getJson('/api/workout-sessions/active')
        ->assertOk()
        ->assertJsonPath('data.exercises.0.effective_exercise.exercise_id', $candidate->id);
    expect($program->plannedExercises()->orderBy('position')->firstOrFail()->exercise_id)
        ->toBe(($replaceInPlan ?? false) ? $candidate->id : $source->id);
})->with(['one workout' => false, 'save in plan' => true, 'default' => null]);

it('replays replacements after catalog deletion without resetting progress and addresses commands by source id', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $source] = $createProgram($user);
    $candidate = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $base = "/api/workout-sessions/{$sessionId}/exercises/{$source->id}";
    $payload = [
        'request_id' => '550e8400-e29b-41d4-a716-446655440000',
        'replacement_exercise_id' => $candidate->id,
        'sets' => [['repetitions' => 10, 'working_weight_kg' => 20]],
    ];
    $this->postJson($base.'/replace', $payload)->assertOk();
    $progress = ['sets' => [['repetitions' => 7, 'working_weight_kg' => 30]]];
    $this->putJson($base.'/sets', $progress)->assertOk();
    $candidate->delete();

    $this->postJson($base.'/replace', $payload)
        ->assertOk()
        ->assertJsonPath('data.exercises.0.sets.0.repetitions', 7);
    $this->postJson($base.'/replace', [...$payload, 'replace_in_plan' => true])
        ->assertConflict()
        ->assertJsonPath('code', 'workout_replacement_request_conflict');
    $this->postJson($base.'/complete', $progress)->assertOk();
    $this->postJson($base.'/reopen')->assertOk();
    $this->postJson($base.'/skip')->assertOk();
    $this->postJson($base.'/reopen')
        ->assertOk()
        ->assertJsonPath('data.exercises.0.sets.0.working_weight_kg', 20);
    $this->postJson("/api/workout-sessions/{$sessionId}/cancel")->assertOk();
    $this->postJson($base.'/replace', $payload)->assertOk();
});

it('validates replacement payload boundaries', function (array $changes, array $errors): void {
    Sanctum::actingAs(User::factory()->create());
    $payload = [
        'request_id' => '550e8400-e29b-41d4-a716-446655440000',
        'replacement_exercise_id' => 1,
        'sets' => [['repetitions' => 10, 'working_weight_kg' => 20]],
    ];

    $this->postJson('/api/workout-sessions/1/exercises/1/replace', array_replace($payload, $changes))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'missing uuid' => [['request_id' => null], ['request_id']],
    'invalid uuid' => [['request_id' => 'invalid'], ['request_id']],
    'missing candidate' => [['replacement_exercise_id' => null], ['replacement_exercise_id']],
    'zero candidate' => [['replacement_exercise_id' => 0], ['replacement_exercise_id']],
    'fractional candidate' => [['replacement_exercise_id' => 1.5], ['replacement_exercise_id']],
    'null flag' => [['replace_in_plan' => null], ['replace_in_plan']],
    'invalid flag' => [['replace_in_plan' => 'yes'], ['replace_in_plan']],
    'empty sets' => [['sets' => []], ['sets']],
    'too many sets' => [['sets' => array_fill(0, 101, ['repetitions' => 10, 'working_weight_kg' => 20])], ['sets']],
    'invalid repetitions' => [['sets' => [['repetitions' => 0, 'working_weight_kg' => 20]]], ['sets.0.repetitions']],
    'weight precision' => [['sets' => [['repetitions' => 10, 'working_weight_kg' => 20.123]]], ['sets.0.working_weight_kg']],
    'weight overflow' => [['sets' => [['repetitions' => 10, 'working_weight_kg' => 1_000_000_000_000_000]]], ['sets.0.working_weight_kg']],
]);

it('returns stable errors for inaccessible replacement targets and incompatible states', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $source, $other] = $createProgram($user);
    $candidate = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;
    $base = "/api/workout-sessions/{$sessionId}/exercises/{$source->id}/replace";
    $payload = [
        'request_id' => '550e8400-e29b-41d4-a716-446655440000',
        'replacement_exercise_id' => $candidate->id,
        'sets' => [['repetitions' => 10, 'working_weight_kg' => 20]],
    ];

    Sanctum::actingAs(User::factory()->create());
    $this->postJson($base, $payload)
        ->assertNotFound()
        ->assertJsonPath('code', 'workout_session_not_found');
    Sanctum::actingAs($user);
    $this->postJson('/api/workout-sessions/999999/exercises/1/replace', $payload)
        ->assertNotFound()
        ->assertJsonPath('code', 'workout_session_not_found');
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/999999/replace", $payload)
        ->assertNotFound()
        ->assertJsonPath('code', 'workout_exercise_not_found');
    $this->postJson($base, [...$payload, 'replacement_exercise_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'workout_replacement_candidate_not_found');
    $this->postJson($base, [...$payload, 'replacement_exercise_id' => $source->id])
        ->assertConflict()
        ->assertJsonPath('code', 'invalid_workout_exercise_replacement');
    $this->postJson($base, [...$payload, 'replacement_exercise_id' => $other->id])
        ->assertConflict()
        ->assertJsonPath('code', 'exercise_already_added_to_workout');
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$source->id}/skip")->assertOk();
    $this->postJson($base, $payload)
        ->assertConflict()
        ->assertJsonPath('code', 'workout_exercise_not_editable');
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$source->id}/reopen")->assertOk();
    $program->delete();
    $this->postJson($base, [...$payload, 'replace_in_plan' => true])
        ->assertConflict()
        ->assertJsonPath('code', 'workout_replacement_plan_conflict');
    $this->getJson('/api/workout-sessions/active')
        ->assertOk()
        ->assertJsonPath('data.exercises.0.replacement', null);
    $this->postJson("/api/workout-sessions/{$sessionId}/cancel")->assertOk();
    $this->postJson($base, $payload)
        ->assertConflict()
        ->assertJsonPath('code', 'workout_session_not_in_progress');
});

it('accepts the maximum replacement set count and safe weight', function () use ($createProgram): void {
    $user = User::factory()->create();
    [$program, $source] = $createProgram($user);
    $candidate = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $sessionId = expect($this->putJson('/api/workout-sessions/active', [
        'training_program_id' => $program->id,
    ])->assertOk()->json('data.id'))->toBeInt()->value;

    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$source->id}/replace", [
        'request_id' => '550e8400-e29b-41d4-a716-446655440000',
        'replacement_exercise_id' => $candidate->id,
        'sets' => array_fill(0, 100, ['repetitions' => 10, 'working_weight_kg' => 1_000_000_000]),
    ])
        ->assertOk()
        ->assertJsonCount(100, 'data.exercises.0.sets')
        ->assertJsonPath('data.exercises.0.sets.99.position', 100)
        ->assertJsonPath('data.exercises.0.sets.99.working_weight_kg', 1_000_000_000);
});

it('explains the required replacement load without referring to completion', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/workout-sessions/1/exercises/1/replace', [
        'request_id' => '550e8400-e29b-41d4-a716-446655440000',
        'replacement_exercise_id' => 1,
        'sets' => [],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.sets.0', 'Для замены упражнения добавьте хотя бы один подход.');
});
