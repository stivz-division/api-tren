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

$sets = static fn (int $count, int $repetitions, int|float $workingWeightInKilograms): array => array_fill(
    0,
    $count,
    ['repetitions' => $repetitions, 'working_weight_kg' => $workingWeightInKilograms],
);

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

it('creates a program with ordered individual planned sets', function (): void {
    $user = User::factory()->create();
    $benchPress = Exercise::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $benchPress->id,
            'sets' => [
                ['repetitions' => 3, 'working_weight_kg' => 80],
                ['repetitions' => 6, 'working_weight_kg' => 100],
                ['repetitions' => 3, 'working_weight_kg' => 120],
                ['repetitions' => 1, 'working_weight_kg' => 130],
            ],
        ]],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.weekday', 1)
        ->assertJsonPath('data.name', 'Тренировка')
        ->assertJsonPath('data.exercises.0.exercise_id', $benchPress->id)
        ->assertJsonPath('data.exercises.0.sets', [
            ['position' => 1, 'repetitions' => 3, 'working_weight_kg' => 80],
            ['position' => 2, 'repetitions' => 6, 'working_weight_kg' => 100],
            ['position' => 3, 'repetitions' => 3, 'working_weight_kg' => 120],
            ['position' => 4, 'repetitions' => 1, 'working_weight_kg' => 130],
        ])
        ->assertJsonPath('data.exercises.0.position', 1)
        ->assertJsonMissingPath('data.user_id');

    $this->assertDatabaseHas('training_programs', [
        'user_id' => $user->id,
        'weekday' => 1,
        'name' => 'Тренировка',
    ]);
    $this->assertDatabaseHas('planned_exercises', [
        'exercise_id' => $benchPress->id,
        'position' => 1,
    ]);
    $this->assertDatabaseCount('planned_sets', 4);
    $this->assertDatabaseHas('planned_sets', [
        'position' => 4,
        'repetitions' => 1,
        'working_weight_grams' => 130_000,
    ]);
});

it('returns 422 when a set working weight has more than two decimal places', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => [['repetitions' => 6, 'working_weight_kg' => 1.255]],
        ]],
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises.0.sets.0.working_weight_kg']);
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

it('validates an ordered non-empty bounded list of complete planned sets', function (
    mixed $plannedSets,
    array $expectedErrors,
): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $plannedSets,
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors($expectedErrors);

    $this->assertDatabaseCount('training_programs', 0);
})->with([
    'empty' => [[], ['exercises.0.sets']],
    'associative' => [
        ['first' => ['repetitions' => 6, 'working_weight_kg' => 100]],
        ['exercises.0.sets'],
    ],
    'more than one hundred' => [
        array_fill(0, 101, ['repetitions' => 6, 'working_weight_kg' => 100]),
        ['exercises.0.sets'],
    ],
    'invalid values' => [
        [['repetitions' => 0, 'working_weight_kg' => -1]],
        [
            'exercises.0.sets.0.repetitions',
            'exercises.0.sets.0.working_weight_kg',
        ],
    ],
]);

it('returns 409 with a stable code when the weekday is already occupied', function () use ($sets): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $payload = [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(3, 6, 100),
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

it('returns the authenticated user program for a weekday', function () use ($sets): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($owner);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 3,
        'name' => 'Грудь и спина',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(4, 8, 2.5),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $ownerResponse = $this->getJson('/api/training-programs/weekdays/3');
    Sanctum::actingAs($otherUser);
    $otherUserResponse = $this->getJson('/api/training-programs/weekdays/3');

    $ownerResponse
        ->assertOk()
        ->assertJsonPath('data.id', $programId)
        ->assertJsonPath('data.exercises.0.sets.0.working_weight_kg', 2.5);
    $otherUserResponse
        ->assertNotFound()
        ->assertExactJson([
            'code' => 'training_program_not_found',
            'message' => 'Программа тренировок не найдена.',
        ]);
});

it('fully replaces the name, exercises, and individual sets without changing the weekday', function () use ($sets): void {
    $user = User::factory()->create();
    $firstExercise = Exercise::factory()->create();
    $secondExercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 5,
        'exercises' => [[
            'exercise_id' => $firstExercise->id,
            'sets' => $sets(3, 6, 100),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $response = $this->putJson('/api/training-programs/'.$programId, [
        'name' => 'Тяжёлая тренировка',
        'exercises' => [[
            'exercise_id' => $secondExercise->id,
            'sets' => [
                ['repetitions' => 3, 'working_weight_kg' => 80],
                ['repetitions' => 6, 'working_weight_kg' => 100],
                ['repetitions' => 3, 'working_weight_kg' => 120],
                ['repetitions' => 1, 'working_weight_kg' => 130],
            ],
        ]],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.weekday', 5)
        ->assertJsonPath('data.name', 'Тяжёлая тренировка')
        ->assertJsonPath('data.exercises.0.exercise_id', $secondExercise->id)
        ->assertJsonPath('data.exercises.0.sets', [
            ['position' => 1, 'repetitions' => 3, 'working_weight_kg' => 80],
            ['position' => 2, 'repetitions' => 6, 'working_weight_kg' => 100],
            ['position' => 3, 'repetitions' => 3, 'working_weight_kg' => 120],
            ['position' => 4, 'repetitions' => 1, 'working_weight_kg' => 130],
        ]);
    $this->assertDatabaseMissing('planned_exercises', [
        'training_program_id' => $programId,
        'exercise_id' => $firstExercise->id,
    ]);
    $this->assertDatabaseHas('planned_exercises', [
        'training_program_id' => $programId,
        'exercise_id' => $secondExercise->id,
    ]);
    $this->assertDatabaseCount('planned_sets', 4);
    $this->assertDatabaseHas('planned_sets', [
        'position' => 4,
        'repetitions' => 1,
        'working_weight_grams' => 130_000,
    ]);
});

it('adds and removes individual sets when updating the same exercise', function () use ($sets): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 6,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(3, 6, 100),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $this->putJson('/api/training-programs/'.$programId, [
        'name' => 'Пирамида',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => [
                ['repetitions' => 3, 'working_weight_kg' => 80],
                ['repetitions' => 6, 'working_weight_kg' => 100],
                ['repetitions' => 3, 'working_weight_kg' => 120],
                ['repetitions' => 1, 'working_weight_kg' => 130],
            ],
        ]],
    ])->assertOk()->assertJsonCount(4, 'data.exercises.0.sets');

    $this->putJson('/api/training-programs/'.$programId, [
        'name' => 'Короткая пирамида',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => [
                ['repetitions' => 5, 'working_weight_kg' => 90],
                ['repetitions' => 2, 'working_weight_kg' => 120],
            ],
        ]],
    ])->assertOk()->assertJsonPath('data.exercises.0.sets', [
        ['position' => 1, 'repetitions' => 5, 'working_weight_kg' => 90],
        ['position' => 2, 'repetitions' => 2, 'working_weight_kg' => 120],
    ]);

    $this->assertDatabaseCount('planned_exercises', 1);
    $this->assertDatabaseCount('planned_sets', 2);
});

it('returns 422 when update attempts to move a program to another weekday', function () use ($sets): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(3, 6, 100),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $response = $this->putJson('/api/training-programs/'.$programId, [
        'weekday' => 2,
        'name' => 'Тренировка',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(3, 6, 100),
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

it('returns 404 when updating another user program', function () use ($sets): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($owner);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 1,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(3, 6, 100),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;
    Sanctum::actingAs($otherUser);

    $response = $this->putJson('/api/training-programs/'.$programId, [
        'name' => 'Чужая программа',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(1, 1, 0),
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

it('hard deletes only the authenticated user program', function () use ($sets): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($user);
    $programId = expect($this->postJson('/api/training-programs', [
        'weekday' => 7,
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(3, 6, 0),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;

    $response = $this->deleteJson('/api/training-programs/'.$programId);

    $response->assertNoContent();
    $this->assertDatabaseMissing('training_programs', ['id' => $programId]);
    $this->assertDatabaseMissing('planned_exercises', [
        'training_program_id' => $programId,
    ]);
    $this->assertDatabaseCount('planned_sets', 0);
});

it('returns 409 with a stable code while schedule mutation is locked', function () use ($sets): void {
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
            'sets' => $sets(3, 6, 100),
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
