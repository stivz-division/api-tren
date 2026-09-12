<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning\EloquentTrainingProgramSnapshotProvider;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

$provider = fn (): EloquentTrainingProgramSnapshotProvider => new EloquentTrainingProgramSnapshotProvider(
    app(DatabaseManager::class),
);

it('returns an ordered immutable snapshot of an owned training program', function () use ($provider): void {
    $user = User::factory()->create();
    $benchPress = Exercise::factory()->create(['name' => 'Жим лежа']);
    $triceps = Exercise::factory()->create(['name' => 'Разгибание на трицепс']);
    $program = TrainingProgramModel::query()->create([
        'user_id' => $user->id,
        'weekday' => 1,
        'name' => 'Грудь и трицепс',
    ]);
    $tricepsPlan = $program->plannedExercises()->create([
        'exercise_id' => $triceps->id,
        'position' => 2,
    ]);
    $tricepsPlan->plannedSets()->createMany([
        ['position' => 1, 'repetitions' => 12, 'working_weight_grams' => 36_000],
        ['position' => 2, 'repetitions' => 10, 'working_weight_grams' => 40_000],
    ]);
    $benchPressPlan = $program->plannedExercises()->create([
        'exercise_id' => $benchPress->id,
        'position' => 1,
    ]);
    $benchPressPlan->plannedSets()->createMany([
        ['position' => 1, 'repetitions' => 3, 'working_weight_grams' => 80_000],
        ['position' => 2, 'repetitions' => 6, 'working_weight_grams' => 100_000],
        ['position' => 3, 'repetitions' => 1, 'working_weight_grams' => 130_000],
    ]);

    $snapshot = $provider()->findForUser(
        new TrainingProgramId($program->id),
        new UserId($user->id),
    );

    if ($snapshot === null) {
        throw new LogicException('Снимок существующей программы должен быть найден.');
    }

    expect([
        $snapshot->trainingProgramId,
        $snapshot->name,
        $snapshot->scheduledWeekday,
    ])->toBe([$program->id, 'Грудь и трицепс', 1]);
    expect(array_map(
        static fn ($exercise): array => [
            $exercise->exerciseId,
            $exercise->name,
            array_map(static fn ($set): array => [
                $set->position,
                $set->repetitions,
                $set->workingWeightInGrams,
            ], $exercise->sets),
            $exercise->position,
        ],
        $snapshot->exercises,
    ))->toBe([
        [$benchPress->id, 'Жим лежа', [[1, 3, 80_000], [2, 6, 100_000], [3, 1, 130_000]], 1],
        [$triceps->id, 'Разгибание на трицепс', [[1, 12, 36_000], [2, 10, 40_000]], 2],
    ]);
});

it('does not expose a training program to another user', function () use ($provider): void {
    $owner = User::factory()->create();
    $anotherUser = User::factory()->create();
    $program = TrainingProgramModel::query()->create([
        'user_id' => $owner->id,
        'weekday' => 1,
        'name' => 'Тренировка',
    ]);

    expect($provider()->findForUser(
        new TrainingProgramId($program->id),
        new UserId($anotherUser->id),
    ))->toBeNull();
});
