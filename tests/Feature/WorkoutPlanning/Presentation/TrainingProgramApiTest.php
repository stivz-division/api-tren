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
    'index' => ['GET', '/api/training-programs'],
    'store' => ['POST', '/api/training-programs'],
    'update' => ['PUT', '/api/training-programs/1'],
    'delete' => ['DELETE', '/api/training-programs/1'],
    'get by weekday' => ['GET', '/api/training-programs/weekdays/1'],
]);

it('returns all authenticated user programs in weekday order with complete details', function () use ($sets): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $mondayExercise = Exercise::factory()->create();
    $tuesdayExercise = Exercise::factory()->create();
    Sanctum::actingAs($owner);
    $tuesdayProgramId = expect($this->postJson('/api/training-programs', [
        'weekday' => 2,
        'name' => 'Вторник',
        'exercises' => [[
            'exercise_id' => $tuesdayExercise->id,
            'sets' => $sets(2, 8, 75.5),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;
    $mondayProgramId = expect($this->postJson('/api/training-programs', [
        'weekday' => 1,
        'name' => 'Понедельник',
        'exercises' => [[
            'exercise_id' => $mondayExercise->id,
            'sets' => $sets(1, 6, 100),
        ]],
    ])->assertCreated()->json('data.id'))->toBeInt()->value;
    Sanctum::actingAs($otherUser);
    $this->postJson('/api/training-programs', [
        'weekday' => 3,
        'name' => 'Чужая программа',
        'exercises' => [[
            'exercise_id' => $mondayExercise->id,
            'sets' => $sets(1, 5, 50),
        ]],
    ])->assertCreated();
    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/training-programs');

    $response
        ->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => $mondayProgramId,
                    'weekday' => 1,
                    'name' => 'Понедельник',
                    'exercises' => [[
                        'exercise_id' => $mondayExercise->id,
                        'position' => 1,
                        'sets' => [[
                            'position' => 1,
                            'repetitions' => 6,
                            'working_weight_kg' => 100,
                        ]],
                    ]],
                ],
                [
                    'id' => $tuesdayProgramId,
                    'weekday' => 2,
                    'name' => 'Вторник',
                    'exercises' => [[
                        'exercise_id' => $tuesdayExercise->id,
                        'position' => 1,
                        'sets' => [
                            [
                                'position' => 1,
                                'repetitions' => 8,
                                'working_weight_kg' => 75.5,
                            ],
                            [
                                'position' => 2,
                                'repetitions' => 8,
                                'working_weight_kg' => 75.5,
                            ],
                        ],
                    ]],
                ],
            ],
        ]);
});

it('returns an empty list when the authenticated user has no programs', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/training-programs');

    $response
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

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

it('creates independent programs on the same weekday and returns them in id order', function () use ($sets): void {
    $owner = User::factory()->create();
    $exercise = Exercise::factory()->create();
    Sanctum::actingAs($owner);
    $payload = [
        'weekday' => 3,
        'exercises' => [['exercise_id' => $exercise->id, 'sets' => $sets(1, 8, 2.5)]],
    ];
    $firstId = expect($this->postJson('/api/training-programs', ['name' => 'Утро'] + $payload)
        ->assertCreated()->json('data.id'))->toBeInt()->value;
    $secondId = $this->postJson('/api/training-programs', ['name' => 'Вечер'] + $payload)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/training-programs', ['weekday' => 4] + $payload)->assertCreated();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/training-programs', $payload)->assertCreated();
    Sanctum::actingAs($owner);

    $this->getJson('/api/training-programs/weekdays/3')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.id', [$firstId, $secondId])
        ->assertJsonPath('data.*.name', ['Утро', 'Вечер'])
        ->assertJsonPath('data.0.exercises.0.sets.0.working_weight_kg', 2.5)
        ->assertJsonPath('data.1.exercises.0.sets.0.repetitions', 8);
    $this->getJson('/api/training-programs')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $firstId)
        ->assertJsonPath('data.1.id', $secondId);

    $this->putJson('/api/training-programs/'.$firstId, [
        'name' => 'Обновлённое утро',
        'exercises' => [['exercise_id' => $exercise->id, 'sets' => $sets(2, 10, 5)]],
    ])->assertOk();
    $this->getJson('/api/training-programs/weekdays/3')->assertOk()
        ->assertJsonPath('data.0.name', 'Обновлённое утро')
        ->assertJsonCount(2, 'data.0.exercises.0.sets')
        ->assertJsonPath('data.1.name', 'Вечер')
        ->assertJsonCount(1, 'data.1.exercises.0.sets')
        ->assertJsonPath('data.1.exercises.0.sets.0.working_weight_kg', 2.5);

    $this->deleteJson('/api/training-programs/'.$firstId)->assertNoContent();
    $this->getJson('/api/training-programs/weekdays/3')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $secondId)
        ->assertJsonPath('data.0.exercises.0.sets.0.repetitions', 8);
});

it('returns an empty weekday list without exposing another users program', function () use ($sets): void {
    Sanctum::actingAs(User::factory()->create());
    $exercise = Exercise::factory()->create();
    $this->postJson('/api/training-programs', [
        'weekday' => 3,
        'exercises' => [['exercise_id' => $exercise->id, 'sets' => $sets(1, 8, 2.5)]],
    ])->assertCreated();
    $this->getJson('/api/training-programs/weekdays/4')->assertOk()->assertExactJson(['data' => []]);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/training-programs/weekdays/3')->assertOk()->assertExactJson(['data' => []]);
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

it('updates a program weekday while retaining its identity', function (int $weekday) use ($sets): void {
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
        'weekday' => $weekday,
        'name' => 'Тренировка',
        'exercises' => [[
            'exercise_id' => $exercise->id,
            'sets' => $sets(3, 6, 100),
        ]],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $programId)
        ->assertJsonPath('data.weekday', $weekday);
    $this->assertDatabaseHas('training_programs', [
        'id' => $programId,
        'weekday' => $weekday,
    ]);
    $this->getJson('/api/training-programs/weekdays/'.$weekday)
        ->assertOk()->assertJsonPath('data.0.id', $programId);
    if ($weekday !== 1) {
        $this->getJson('/api/training-programs/weekdays/1')->assertOk()->assertExactJson(['data' => []]);
    }
})->with([1, 2, 7]);

it('moves a program to an occupied weekday without changing the other program', function () use ($sets): void {
    Sanctum::actingAs(User::factory()->create());
    $exercise = Exercise::factory()->create();
    $payload = [
        'name' => 'Исходная программа',
        'exercises' => [['exercise_id' => $exercise->id, 'sets' => $sets(1, 6, 100)]],
    ];
    $programId = expect($this->postJson('/api/training-programs', ['weekday' => 1] + $payload)
        ->assertCreated()->json('data.id'))->toBeInt()->value;
    $otherId = $this->postJson('/api/training-programs', ['weekday' => 2] + $payload)->assertCreated()->json('data.id');

    $this->putJson('/api/training-programs/'.$programId, [
        'weekday' => 2,
        'name' => 'Изменённая программа',
        'exercises' => [['exercise_id' => $exercise->id, 'sets' => $sets(1, 10, 50)]],
    ])->assertOk()->assertJsonPath('data.id', $programId);

    $this->assertDatabaseHas('training_programs', [
        'id' => $programId, 'weekday' => 2, 'name' => 'Изменённая программа',
    ]);
    $this->getJson('/api/training-programs/weekdays/1')->assertOk()->assertExactJson(['data' => []]);
    $this->getJson('/api/training-programs/weekdays/2')->assertOk()
        ->assertJsonPath('data.*.id', [$programId, $otherId])
        ->assertJsonPath('data.0.exercises.0.sets.0.repetitions', 10)
        ->assertJsonPath('data.1.exercises.0.sets.0.repetitions', 6)
        ->assertJsonPath('data.1.exercises.0.sets.0.working_weight_kg', 100);
});

it('rejects an invalid weekday when updating a program', function (mixed $weekday) use ($sets): void {
    Sanctum::actingAs(User::factory()->create());
    $exercise = Exercise::factory()->create();
    $payload = [
        'name' => 'Тренировка',
        'exercises' => [['exercise_id' => $exercise->id, 'sets' => $sets(1, 6, 100)]],
    ];
    $programId = expect($this->postJson('/api/training-programs', ['weekday' => 1] + $payload)
        ->assertCreated()->json('data.id'))->toBeInt()->value;

    $this->putJson('/api/training-programs/'.$programId, ['weekday' => $weekday] + $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['weekday']);

    $this->assertDatabaseHas('training_programs', ['id' => $programId, 'weekday' => 1]);
})->with([0, 8, 1.5, 'Monday', null, '']);

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
        'weekday' => 2,
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
