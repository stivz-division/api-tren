<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutPlanning\Application\Exceptions\TrainingProgramMutationInProgress;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutPlanning\SynchronousTrainingProgramMutationLock;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->app->instance(
        TrainingProgramMutationLock::class,
        new SynchronousTrainingProgramMutationLock,
    );
});

it('returns 401 for every training program endpoint without a token', function (
    string $method,
    string $uri,
): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    'store' => ['POST', '/api/training-programs'],
    'update' => ['PUT', '/api/training-programs/1'],
    'delete' => ['DELETE', '/api/training-programs/1'],
    'get by weekday' => ['GET', '/api/training-programs/weekdays/1'],
]);

it('creates a program and converts kilograms to grams', function (): void {
    $user = User::factory()->create();
    $benchPress = Exercise::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $benchPress->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 1.25,
        ]],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.weekday', 1)
        ->assertJsonPath('data.name', 'Тренировка')
        ->assertJsonPath('data.exercises.0.exercise_id', $benchPress->id)
        ->assertJsonPath('data.exercises.0.sets', 3)
        ->assertJsonPath('data.exercises.0.repetitions_per_set', 6)
        ->assertJsonPath('data.exercises.0.working_weight_kg', 1.25)
        ->assertJsonPath('data.exercises.0.position', 1)
        ->assertJsonMissingPath('data.user_id');

    $this->assertDatabaseHas('training_programs', [
        'user_id' => $user->id,
        'weekday' => 1,
        'name' => 'Тренировка',
    ]);
    $this->assertDatabaseHas('planned_exercises', [
        'exercise_id' => $benchPress->id,
        'sets' => 3,
        'repetitions_per_set' => 6,
        'working_weight_grams' => 1250,
        'position' => 1,
    ]);
});

it('returns 422 when working weight has more than two decimal places', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 1.255,
        ]],
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises.0.working_weight_kg']);
    $this->assertDatabaseCount('training_programs', 0);
});

it('returns 422 when a program has no exercises', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [],
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises']);
    $this->assertDatabaseCount('training_programs', 0);
});

it('returns 409 with a stable code when the weekday is already occupied', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $payload = [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 100,
        ]],
    ];
    $this->postJson('/api/training-programs', $payload)->assertCreated();

    $response = $this->postJson('/api/training-programs', $payload);

    $response
        ->assertConflict()
        ->assertExactJson([
            'code' => 'training_program_already_exists',
            'message' => 'На этот день уже создана программа тренировок.',
        ]);
    $this->assertDatabaseCount('training_programs', 1);
});

it('returns the authenticated user program for a weekday', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($owner);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 3,
        'name' => 'Грудь и спина',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 4,
            'repetitions_per_set' => 8,
            'working_weight_kg' => 2.5,
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $ownerResponse = $this->getJson('/api/training-programs/weekdays/3');
    Sanctum::actingAs($otherUser);
    $otherUserResponse = $this->getJson('/api/training-programs/weekdays/3');

    $ownerResponse
        ->assertOk()
        ->assertJsonPath('data.id', $programId)
        ->assertJsonPath('data.exercises.0.working_weight_kg', 2.5);
    $otherUserResponse
        ->assertNotFound()
        ->assertExactJson([
            'code' => 'training_program_not_found',
            'message' => 'Программа тренировок не найдена.',
        ]);
});

it('fully replaces the name and exercises without changing the weekday', function (): void {
    $user = User::factory()->create();
    $firstExercise = Exercise::factory()->create();
    $secondExercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 5,
        'exercises' => [[
            'exercise_id' => $firstExercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 100,
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $response = $this->putJson('/api/training-programs/'.$programId, [
        'name' => 'Тяжёлая тренировка',
        'exercises' => [[
            'exercise_id' => $secondExercise->id,
            'sets' => 5,
            'repetitions_per_set' => 5,
            'working_weight_kg' => 102.5,
        ]],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.weekday', 5)
        ->assertJsonPath('data.name', 'Тяжёлая тренировка')
        ->assertJsonPath('data.exercises.0.exercise_id', $secondExercise->id)
        ->assertJsonPath('data.exercises.0.working_weight_kg', 102.5);
    $this->assertDatabaseMissing('planned_exercises', [
        'training_program_id' => $programId,
        'exercise_id' => $firstExercise->id,
    ]);
    $this->assertDatabaseHas('planned_exercises', [
        'training_program_id' => $programId,
        'exercise_id' => $secondExercise->id,
        'working_weight_grams' => 102500,
    ]);
});

it('returns 422 when update attempts to move a program to another weekday', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 100,
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $response = $this->putJson('/api/training-programs/'.$programId, [
        'weekday' => 2,
        'name' => 'Тренировка',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 100,
        ]],
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['weekday']);
    $this->assertDatabaseHas('training_programs', [
        'id' => $programId,
        'weekday' => 1,
    ]);
});

it('returns 404 when updating another user program', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($owner);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 100,
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;
    Sanctum::actingAs($otherUser);

    $response = $this->putJson('/api/training-programs/'.$programId, [
        'name' => 'Чужая программа',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 1,
            'repetitions_per_set' => 1,
            'working_weight_kg' => 0,
        ]],
    ]);

    $response
        ->assertNotFound()
        ->assertJsonPath('code', 'training_program_not_found');
    $this->assertDatabaseHas('training_programs', [
        'id' => $programId,
        'name' => 'Тренировка',
    ]);
});

it('hard deletes only the authenticated user program', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 7,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 0,
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $response = $this->deleteJson('/api/training-programs/'.$programId);

    $response->assertNoContent();
    $this->assertDatabaseMissing('training_programs', ['id' => $programId]);
    $this->assertDatabaseMissing('planned_exercises', [
        'training_program_id' => $programId,
    ]);
});

it('returns 409 with a stable code while schedule mutation is locked', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $this->app->instance(
        TrainingProgramMutationLock::class,
        new class implements TrainingProgramMutationLock
        {
            public function execute(UserId $userId, Closure $callback): mixed
            {
                throw new TrainingProgramMutationInProgress;
            }
        },
    );

    $response = $this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => 3,
            'repetitions_per_set' => 6,
            'working_weight_kg' => 100,
        ]],
    ]);

    $response
        ->assertConflict()
        ->assertExactJson([
            'code' => 'training_program_mutation_in_progress',
            'message' => 'Изменение расписания уже выполняется. Повторите попытку.',
        ]);
    $this->assertDatabaseCount('training_programs', 0);
});
